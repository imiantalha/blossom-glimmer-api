<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use App\Constants\Role; 

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if (!$actor || !$actor->can('update', $target)) return false;

        $privilegedTarget = $target && ($target->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]));
        $assigningPrivilegedRole = in_array($this->input('role'), [Role::ADMIN, Role::SUPER_ADMIN], true);

        return !($privilegedTarget || $assigningPrivilegedRole) || $actor->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:50'],
            'email'     => ['sometimes', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email,' . $this->user->id],
            'password'  => ['sometimes', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols() /**->uncompromised() */ ],
            'password_confirmation' => ['sometimes'],
            'role' => ['required', 'exists:roles,name'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
