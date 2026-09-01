<?php

use Illuminate\Http\Request;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\EmailController;

Route::get('/user', function (Request $request) {
    return new UserResource($request->user());
})->middleware('auth:sanctum');


Route::middleware('throttle:60,1')->group(function () {
    
    Route::controller(AuthController::class)->group(function () {
        Route::post('/register', 'register');
        Route::post('/login', 'login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', 'me');
            Route::post('/logout', 'logout');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        
        /*
        |--------------------------------------------------------------------------
        | User Management
        |--------------------------------------------------------------------------
        */
        
        Route::apiResource('users', UserController::class);

        Route::get('users/options', [UserController::class, 'options']);

        /*
        |--------------------------------------------------------------------------
        | Role Management
        |--------------------------------------------------------------------------
        */

        Route::get('roles/options', [RoleController::class, 'options']);

        Route::apiResource('roles', RoleController::class);

        /*
        |--------------------------------------------------------------------------
        | Permission Management
        |--------------------------------------------------------------------------
        */

        Route::get('permissions/options', [PermissionController::class, 'options']);

        Route::apiResource('permissions', PermissionController::class);

        Route::get('email-logs', [EmailLogController::class, 'index']);
        Route::get('email-logs/{emailLog}', [EmailLogController::class, 'show']);
        Route::delete('email-logs/{emailLog}', [EmailLogController::class, 'destroy']);
        Route::post('email-logs/{emailLog}/retry', [EmailLogController::class, 'retry']);

        Route::post('/emails/send', [EmailController::class, 'send']);
    });
});