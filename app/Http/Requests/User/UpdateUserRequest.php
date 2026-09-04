<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password; 

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
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
        ];
    }
}
