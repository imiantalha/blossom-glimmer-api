<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

            // The request contract accepts one role. Assign it explicitly so
            // newly created admins, vendors and customers receive the intended
            // authorization boundary instead of silently becoming role-less.
            $role = $data['role'] ?? null;
            unset($data['role'], $data['password_confirmation']);

            // Create user
            $user = $this->userRepository->create($data);

            // Assign role
            if ($role) {
                $user->syncRoles([$role]);
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

            unset($data['role'], $data['password_confirmation']);

            // Prevent an administrator from removing their own access through a direct API call.
            if ((int) auth()->id() === (int) $user->id) {
                if (array_key_exists('is_active', $data) && !$data['is_active']) {
                    throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
                }
                if (!is_null($roles) && !$user->hasRole($roles)) {
                    throw ValidationException::withMessages(['role' => 'You cannot change your own administrator role.']);
                }
            }

            // Never allow the marketplace to be left without an active administrator.
            if (array_key_exists('is_active', $data) && !$data['is_active'] && $user->hasRole('Admin')) {
                $activeAdmins = User::role('Admin')->where('is_active', true)->count();
                if ($activeAdmins <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => 'The last active administrator cannot be deactivated.',
                    ]);
                }
            }

            // Update user
            $user = $this->userRepository->update($user, $data);

            // Revoke all existing API tokens immediately when an account is disabled.
            if (!$user->is_active) {
                $user->tokens()->delete();
            }

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