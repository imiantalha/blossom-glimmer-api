<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * Get paginated users.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->userRepository->paginate($filters);
    }

    /**
     * Get a single user.
     */
    public function find(User $user): User
    {
        return $this->userRepository->find($user);
    }

    /**
     * Create a new user.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {

            // Hash password
            $data['password'] = Hash::make($data['password']);

            // Extract roles
            $roles = $data['roles'] ?? [];

            unset($data['roles']);

            // Create user
            $user = $this->userRepository->create($data);

            // Assign roles
            if (!empty($roles)) {
                $user->syncRoles($roles);
            }

            return $user->load('roles');
        });
    }

    /**
     * Update existing user.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {

            // Hash password only if provided
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Extract role
            $roles = $data['role'] ?? null;

            unset($data['role']);

            // Update user
            $user = $this->userRepository->update($user, $data);

            // Sync role
            if (!is_null($roles)) {
                $user->syncRoles([$roles]);
            }

            return $user->load('roles');
        });
    }

    /**
     * Delete user.
     */
    public function delete(User $user): bool
    {
        return DB::transaction(function () use ($user) {

            // Remove assigned roles
            $user->syncRoles([]);

            return $this->userRepository->delete($user);
        });
    }
}