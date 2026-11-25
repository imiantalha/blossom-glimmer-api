<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoleService
{
    public function __construct(
        protected RoleRepositoryInterface $roleRepository
    ) {
    }

    /**
     * Get paginated roles.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->roleRepository->paginate($filters);
    }

    /**
     * Find a role.
     */
    public function find(Role $role): Role
    {
        return $this->roleRepository->find($role);
    }

    /**
     * Create a role.
     */
    public function create(array $data): Role
    {
        $data['guard_name'] ??= 'web';

        return DB::transaction(function () use ($data) {

            $permissions = $data['permissions'] ?? [];

            unset($data['permissions']);

            $role = $this->roleRepository->create($data);

            if (!empty($permissions)) {
                $role->syncPermissions($permissions);
            }

            return $role->load('permissions');
        });
    }

    /**
     * Update a role.
     */
    public function update(
        Role $role,
        array $data
    ): Role {

        return DB::transaction(function () use ($role, $data) {

            $permissions = $data['permissions'] ?? null;

            unset($data['permissions']);

            $role = $this->roleRepository->update(
                $role,
                $data
            );

            if (!is_null($permissions)) {
                $role->syncPermissions($permissions);
            }

            return $role->load('permissions');
        });
    }

    /**
     * Delete a role.
     */
    public function delete(Role $role): bool
    {
        return DB::transaction(function () use ($role) {

            $role->syncPermissions([]);

            return $this->roleRepository->delete($role);
        });
    }

    /**
     * Get roles for dropdowns.
     */
    public function options(): Collection
    {
        return $this->roleRepository->options();
    }
}