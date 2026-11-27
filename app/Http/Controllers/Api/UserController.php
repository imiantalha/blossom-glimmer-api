<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Constants\Role;
use Illuminate\Support\Facades\DB;
use App\Support\ApiResponse;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Events\UserRegistered;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {
    }

    /**
     * Marketplace customer directory with purchase summaries. This endpoint is
     * intentionally customer-role scoped; vendor/admin accounts never appear.
     */
    public function customers(IndexUserRequest $request): JsonResponse
    {
        $query = User::role(Role::CUSTOMER)->with('roles:id,name');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $sort = in_array($request->input('sort'), ['name', 'email', 'created_at'], true)
            ? $request->input('sort') : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $customers = $query->orderBy($sort, $direction)
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        $ids = $customers->getCollection()->pluck('id');
        $stats = DB::table('customer_orders')
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, COUNT(*) as order_count, COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total ELSE 0 END), 0) as lifetime_value, MAX(created_at) as last_order_at', ['cancelled', 'refunded'])
            ->groupBy('user_id')
            ->get()->keyBy('user_id');

        $data = $customers->getCollection()->map(function (User $customer) use ($stats) {
            $summary = $stats->get($customer->id);
            return [
                'id' => (string) $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'isActive' => (bool) $customer->is_active,
                'createdAt' => $customer->created_at?->toISOString(),
                'orderCount' => (int) ($summary->order_count ?? 0),
                'lifetimeValue' => (float) ($summary->lifetime_value ?? 0),
                'lastOrderAt' => $summary?->last_order_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Customers retrieved successfully.',
            'data' => $data,
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'count' => $data->count(),
                'from' => $customers->firstItem(),
                'to' => $customers->lastItem(),
                'has_previous' => $customers->currentPage() > 1,
                'has_next' => $customers->hasMorePages(),
            ],
        ]);
    }

    /**
     * Customer detail plus recent orders, without exposing authentication data.
     */
    public function customer(User $user): JsonResponse
    {
        abort_unless($user->hasRole(Role::CUSTOMER), 404);

        $orders = DB::table('customer_orders')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'number', 'status', 'payment_status', 'fulfillment_status', 'currency', 'total', 'created_at']);

        $summary = DB::table('customer_orders')->where('user_id', $user->id)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total ELSE 0 END), 0) as lifetime_value', ['cancelled', 'refunded'])
            ->first();

        return ApiResponse::successResponse([
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'isActive' => (bool) $user->is_active,
            'createdAt' => $user->created_at?->toISOString(),
            'orderCount' => (int) ($summary->order_count ?? 0),
            'lifetimeValue' => (float) ($summary->lifetime_value ?? 0),
            'recentOrders' => $orders->map(fn ($order) => [
                'id' => (string) $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'fulfillmentStatus' => $order->fulfillment_status,
                'currency' => $order->currency,
                'total' => (float) $order->total,
                'createdAt' => $order->created_at,
            ])->values(),
        ], 'Customer retrieved successfully.');
    }

    /**
     * Return compact user records for administrative selectors.
     */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'email']);

        return ApiResponse::successResponse(
            $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
                'label' => $user->name . ' (' . $user->email . ')',
                'value' => $user->id,
            ]),
            'User options retrieved successfully.'
        );
    }

    /**
     * Display a paginated listing of users.
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->paginate(
            $request->validated()
        );

        return ApiResponse::paginatedResponse(
            UserResource::collection($users),
            'Users retrieved successfully.'
        );
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $this->userService->create(
            $request->validated()
        );

        UserRegistered::dispatch($user);

        return ApiResponse::createdResponse(
            new UserResource($user),
            'User created successfully.'
        );
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user = $this->userService->find($user);

        return ApiResponse::successResponse(
            new UserResource($user),
            'User retrieved successfully.'
        );
    }

    /**
     * Update the specified user.
     */
    public function update(
        UpdateUserRequest $request,
        User $user
    ): JsonResponse {

        $this->authorize('update', $user);

        $user = $this->userService->update(
            $user,
            $request->validated()
        );

        return ApiResponse::successResponse(
            new UserResource($user),
            'User updated successfully.'
        );
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $this->userService->delete($user);

        return ApiResponse::deletedResponse(
            'User deleted successfully.'
        );
    }
}