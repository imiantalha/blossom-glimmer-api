<?php

namespace App\Http\Controllers\Api;

use App\Constants\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials)) {
            return ApiResponse::unauthorizedResponse('Invalid email or password.');
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $token = $user->createToken('storefront', ['*'], now()->addMinutes((int) config('sanctum.expiration', 10080)))->plainTextToken;

        return ApiResponse::successResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addMinutes((int) config('sanctum.expiration', 10080))->toISOString(),
        ], 'Login successful.');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => trim($data['firstName'] . ' ' . $data['lastName']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $user->assignRole(Role::CUSTOMER);

        $token = $user->createToken('storefront', ['*'], now()->addMinutes((int) config('sanctum.expiration', 10080)))->plainTextToken;

        return ApiResponse::createdResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addMinutes((int) config('sanctum.expiration', 10080))->toISOString(),
        ], 'Registration successful.');
    }

    public function me(Request $request)
    {
        return ApiResponse::successResponse(new UserResource($request->user()), 'User details retrieved.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return ApiResponse::successResponse(null, 'Logout successful.');
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($data);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return ApiResponse::successResponse(null, __($status));
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
            $user->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return ApiResponse::successResponse(null, __($status));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $request->user()->forceFill(['password' => $data['password']])->save();
        $currentToken = $request->user()->currentAccessToken();
        $request->user()->tokens()->when($currentToken, fn ($query) => $query->where('id', '<>', $currentToken->id))->delete();

        return ApiResponse::successResponse(null, 'Password updated successfully.');
    }
}
