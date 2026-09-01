<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::guest() || $this->user()?->can('users.create');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:50'],
            'email'     => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password'  => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols() /**->uncompromised() */ ],
            'password_confirmation' => ['required'],
            'role' => ['required', 'exists:roles,name'],
        ];
    }
}
