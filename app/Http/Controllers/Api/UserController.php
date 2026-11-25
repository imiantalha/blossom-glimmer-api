<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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