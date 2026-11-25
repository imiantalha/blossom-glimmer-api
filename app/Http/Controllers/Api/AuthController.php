<?php

namespace App\Http\Controllers\Api;

use App\Constants\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
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
        $credentials['email'] = strtolower(trim($credentials['email']));

        $user = User::where('email', $credentials['email'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return ApiResponse::unauthorizedResponse('Invalid email or password.');
        }
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 10080));
        $token = $user->createToken('storefront', ['*'], $expiresAt)->plainTextToken;

        return ApiResponse::successResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
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
        $data['email'] = strtolower(trim($data['email']));

        $user = User::create([
            'name' => trim($data['firstName'] . ' ' . $data['lastName']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $user->assignRole(Role::CUSTOMER);

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 10080));
        $token = $user->createToken('storefront', ['*'], $expiresAt)->plainTextToken;

        return ApiResponse::createdResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
        ], 'Registration successful.');
    }

    public function registerVendor(Request $request)
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $data['email'] = strtolower(trim($data['email']));

        $user = User::create([
            'name' => trim($data['firstName'] . ' ' . $data['lastName']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $user->assignRole(Role::VENDOR);

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 10080));
        $token = $user->createToken('storefront-vendor', ['*'], $expiresAt)->plainTextToken;

        return ApiResponse::createdResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
        ], 'Vendor account created successfully. Create your store to start selling.');
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
        $data['email'] = strtolower(trim($data['email']));
        // The broker can return different statuses for unknown, throttled, and
        // deliverable accounts. Keep the public response identical in every case.
        try {
            Password::sendResetLink($data);
        } catch (Throwable $exception) {
            Log::error('Password reset notification delivery failed.', [
                'exception' => $exception::class,
            ]);
        }

        return ApiResponse::successResponse(
            null,
            'If an account exists for this email, password reset instructions will be sent.'
        );
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $data['email'] = strtolower(trim($data['email']));

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
