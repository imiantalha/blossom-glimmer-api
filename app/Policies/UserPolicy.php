<?php

namespace App\Policies;

use App\Models\User;
use App\Constants\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]) || $user->can('users.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]) || $user->can('users.create') || Auth::guest();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]) || $user->can('users.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]) || $user->can('users.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]);
    }
}
