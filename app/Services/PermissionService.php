<?php

namespace App\Services;

use Spatie\Permission\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    public function __construct(
        protected PermissionRepositoryInterface $permissionRepository
    ) {
    }

    /**
     * Get paginated permissions.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->permissionRepository->paginate($filters);
    }

    /**
     * Find a permission.
     */
    public function find(Permission $permission): Permission
    {
        return $this->permissionRepository->find($permission);
    }

    /**
     * Create a permission.
     */
    public function create(array $data): Permission
    {
        $data['guard_name'] ??= 'web';

        return DB::transaction(function () use ($data) {

            return $this->permissionRepository->create($data);

        });
    }

    /**
     * Update a permission.
     */
    public function update(
        Permission $permission,
        array $data
    ): Permission {

        return DB::transaction(function () use ($permission, $data) {

            return $this->permissionRepository->update(
                $permission,
                $data
            );

        });
    }

    /**
     * Delete a permission.
     */
    public function delete(Permission $permission): bool
    {
        return DB::transaction(function () use ($permission) {

            // Prevent deleting permissions assigned to roles
            if ($permission->roles()->exists()) {
                $permission->roles()->detach();
            }

            return $this->permissionRepository->delete($permission);

        });
    }

    /**
     * Get permissions for dropdown/select.
     */
    public function options(): Collection
    {
        return $this->permissionRepository->options();
    }
}