<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Support\ApiResponse;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\RoleResource;
use App\Http\Requests\Role\IndexRoleRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService
    ) {
    }

    /**
     * Display a paginated listing of roles.
     */
    public function index(IndexRoleRequest $request): JsonResponse
    {
        // $this->authorize('viewAny', Role::class);

        $roles = $this->roleService->paginate(
            $request->validated()
        );

        return ApiResponse::paginatedResponse(
            RoleResource::collection($roles),
            'Roles retrieved successfully.'
        );
    }

    /**
     * Store a newly created role.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roleService->create(
            $request->validated()
        );

        return ApiResponse::createdResponse(
            new RoleResource($role),
            'Role created successfully.'
        );
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role = $this->roleService->find($role);

        return ApiResponse::successResponse(
            new RoleResource($role),
            'Role retrieved successfully.'
        );
    }

    /**
     * Update the specified role.
     */
    public function update(
        UpdateRoleRequest $request,
        Role $role
    ): JsonResponse {
        $this->authorize('update', $role);

        $role = $this->roleService->update(
            $role,
            $request->validated()
        );

        return ApiResponse::successResponse(
            new RoleResource($role),
            'Role updated successfully.'
        );
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->delete($role);

        return ApiResponse::deletedResponse(
            'Role deleted successfully.'
        );
    }

    /**
     * Get roles for dropdown/select.
     */
    public function options(): JsonResponse
    {
        $roles = $this->roleService->options();

        return ApiResponse::successResponse(
            $roles,
            'Roles retrieved successfully.'
        );
    }
}