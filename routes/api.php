<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\ProductAssistantController;
use App\Http\Controllers\Api\EmailLogController;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/health', fn () => response()->json(['success' => true, 'message' => 'API is healthy.', 'data' => ['version' => 'v1']]));

    // Public storefront catalog.
    Route::get('products', [StorefrontController::class, 'products']);
    Route::get('products/{slug}', [StorefrontController::class, 'product']);
    Route::get('categories', [StorefrontController::class, 'categories']);
    Route::get('categories/{slug}', [StorefrontController::class, 'category']);
    Route::get('brands', [StorefrontController::class, 'brands']);
    Route::get('brands/{slug}', [StorefrontController::class, 'brand']);
    Route::get('brands/{slug}/products', [StorefrontController::class, 'brandProducts']);
    Route::get('vendors', [StorefrontController::class, 'vendors']);
    Route::get('vendors/{slug}', [StorefrontController::class, 'vendor']);
    Route::get('vendors/{slug}/products', [StorefrontController::class, 'vendorProducts']);
    Route::get('collections', [StorefrontController::class, 'collections']);
    Route::get('collections/{slug}', [StorefrontController::class, 'collection']);
    Route::get('collections/{slug}/products', [StorefrontController::class, 'collectionProducts']);
    Route::get('reviews', [StorefrontController::class, 'reviews']);
    Route::get('shipping/methods', [StorefrontController::class, 'shippingMethods']);
    Route::get('payment/methods', [StorefrontController::class, 'paymentMethods']);

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('forgot-password', 'forgotPassword');
        Route::post('reset-password', 'resetPassword');
        Route::post('login', 'login');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', 'me');
            Route::post('logout', 'logout');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', fn (Request $request) => new UserResource($request->user()));
        Route::get('users/me', [AuthController::class, 'me']);
        Route::patch('users/me', [StorefrontController::class, 'updateProfile']);
        Route::patch('users/me/password', [AuthController::class, 'changePassword']);
        Route::get('users/me/addresses', [StorefrontController::class, 'addresses']);
        Route::post('users/me/addresses', [StorefrontController::class, 'storeAddress']);
        Route::patch('users/me/addresses/{id}', [StorefrontController::class, 'updateAddress']);
        Route::delete('users/me/addresses/{id}', [StorefrontController::class, 'deleteAddress']);

        Route::get('cart', [StorefrontController::class, 'cart']);
        Route::post('cart', [StorefrontController::class, 'addCartItem']);
        Route::patch('cart/{id}', [StorefrontController::class, 'updateCartItem']);
        Route::delete('cart/{id}', [StorefrontController::class, 'deleteCartItem']);
        Route::delete('cart', [StorefrontController::class, 'clearCart']);

        Route::get('orders', [StorefrontController::class, 'orders']);
        Route::post('orders', [StorefrontController::class, 'createOrder']);
        Route::get('orders/{id}', [StorefrontController::class, 'order']);

        Route::get('wishlist', [StorefrontController::class, 'wishlist']);
        Route::post('wishlist', [StorefrontController::class, 'addWishlist']);
        Route::delete('wishlist/{id}', [StorefrontController::class, 'deleteWishlist']);
        Route::post('reviews', [StorefrontController::class, 'createReview']);

        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::apiResource('admin/users', UserController::class);
        Route::get('admin/users/options', [UserController::class, 'options']);
        Route::get('roles/options', [RoleController::class, 'options']);
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions/options', [PermissionController::class, 'options']);
        Route::apiResource('permissions', PermissionController::class);
        Route::get('email-logs', [EmailLogController::class, 'index']);
        Route::get('email-logs/{emailLog}', [EmailLogController::class, 'show']);
        Route::delete('email-logs/{emailLog}', [EmailLogController::class, 'destroy']);
        Route::post('email-logs/{emailLog}/retry', [EmailLogController::class, 'retry']);
        Route::post('emails/send', [EmailController::class, 'send']);

        Route::apiResource('admin/products', ProductController::class);
        Route::apiResource('admin.products.variants', ProductVariantController::class);
        Route::post('ai/product-assistant', [ProductAssistantController::class, 'chat']);
        Route::post('ai/product-assistant/stream', [ProductAssistantController::class, 'stream']);
    });
});
