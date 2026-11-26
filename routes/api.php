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
use App\Http\Controllers\Api\VendorProductController;
use App\Http\Controllers\Api\VendorDashboardController;
use App\Http\Controllers\Api\CatalogManagementController;
use App\Http\Controllers\Api\CatalogRequestController;
use App\Http\Controllers\Api\ReviewManagementController;
use App\Http\Controllers\ProductAssistantController;
use App\Http\Controllers\Api\EmailLogController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\PaymentSimulationController;
use App\Http\Controllers\Api\VendorFinanceController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\VendorReviewController;
use App\Http\Controllers\Api\CouponManagementController;

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
        Route::post('register/vendor', 'registerVendor');
        Route::post('forgot-password', 'forgotPassword');
        Route::post('reset-password', 'resetPassword');
        Route::post('login', 'login');
        Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
            Route::get('me', 'me');
            Route::post('logout', 'logout');
        });
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('user', fn (Request $request) => new UserResource($request->user()));
        Route::get('users/me', [AuthController::class, 'me']);
        Route::get('notifications', [EngagementController::class, 'notifications']);
        Route::patch('notifications/{id}/read', [EngagementController::class, 'markNotificationRead']);
        Route::post('notifications/read-all', [EngagementController::class, 'markAllNotificationsRead']);
        Route::patch('users/me', [StorefrontController::class, 'updateProfile']);
        Route::patch('users/me/password', [AuthController::class, 'changePassword']);
        Route::get('users/me/addresses', [StorefrontController::class, 'addresses']);
        Route::post('users/me/addresses', [StorefrontController::class, 'storeAddress']);
        Route::patch('users/me/addresses/{id}', [StorefrontController::class, 'updateAddress']);
        Route::delete('users/me/addresses/{id}', [StorefrontController::class, 'deleteAddress']);

        Route::middleware('role:Customer')->group(function () {
            Route::post('shipping/quote', [StorefrontController::class, 'shippingQuote']);
            Route::post('inventory/validate', [StorefrontController::class, 'validateInventory']);
        });

        Route::middleware('role:Vendor')->prefix('vendor')->group(function () {
            Route::get('dashboard', [VendorDashboardController::class, 'index']);
            Route::get('reviews', [VendorReviewController::class, 'index'])->middleware('permission:reviews.view');
            Route::patch('reviews/{id}/reply', [VendorReviewController::class, 'reply'])->middleware('permission:reviews.update');
            Route::get('support/tickets', [EngagementController::class, 'tickets']);
            Route::post('support/tickets', [EngagementController::class, 'createTicket']);
            Route::get('support/tickets/{id}', [EngagementController::class, 'showTicket']);
            Route::post('support/tickets/{id}/messages', [EngagementController::class, 'replyTicket']);
            Route::get('finance/summary', [VendorFinanceController::class, 'summary']);
            Route::get('finance/payouts', [VendorFinanceController::class, 'payouts']);
            Route::post('finance/payouts', [VendorFinanceController::class, 'requestPayout']);
            Route::get('store/me', [StorefrontController::class, 'myStore'])->middleware('permission:stores.manage');
            Route::get('orders', [StorefrontController::class, 'vendorOrders'])->middleware('permission:orders.view');
            Route::get('orders/{id}', [StorefrontController::class, 'vendorOrder'])->middleware('permission:orders.view');
            Route::patch('orders/{orderId}/fulfillment', [StorefrontController::class, 'updateVendorFulfillment'])->middleware('permission:orders.update');
            Route::post('store', [StorefrontController::class, 'createStore'])->middleware('permission:stores.manage');
            Route::patch('store', [StorefrontController::class, 'updateMyStore'])->middleware('permission:stores.manage');
            Route::get('catalog-requests', [CatalogRequestController::class, 'vendorIndex'])->middleware('permission:products.view');
            Route::post('catalog-requests', [CatalogRequestController::class, 'store'])->middleware('permission:products.create');
            Route::get('products/options', [VendorProductController::class, 'options'])->middleware('permission:products.view');
            Route::get('products', [VendorProductController::class, 'index'])->middleware('permission:products.view');
            Route::post('products', [VendorProductController::class, 'store'])->middleware('permission:products.create');
            Route::get('products/{id}', [VendorProductController::class, 'show'])->middleware('permission:products.view');
            Route::patch('products/{id}', [VendorProductController::class, 'update'])->middleware('permission:products.update');
            Route::delete('products/{id}', [VendorProductController::class, 'destroy'])->middleware('permission:products.delete');
            Route::patch('products/{id}/inventory', [VendorProductController::class, 'updateInventory'])->middleware('permission:inventory.update');
        });

        Route::middleware('role:Customer')->group(function () {
        Route::post('coupons/validate', [StorefrontController::class, 'validateCoupon']);
        Route::get('cart', [StorefrontController::class, 'cart']);
        Route::post('cart/merge', [StorefrontController::class, 'mergeCart']);
        Route::post('cart', [StorefrontController::class, 'addCartItem']);
        Route::patch('cart/{id}', [StorefrontController::class, 'updateCartItem']);
        Route::delete('cart/{id}', [StorefrontController::class, 'deleteCartItem']);
        Route::delete('cart', [StorefrontController::class, 'clearCart']);


        Route::get('orders', [StorefrontController::class, 'orders']);
        Route::post('orders', [StorefrontController::class, 'createOrder']);
        Route::get('orders/{id}', [StorefrontController::class, 'order']);

        Route::get('wishlist', [StorefrontController::class, 'wishlist']);
        Route::post('wishlist/merge', [StorefrontController::class, 'mergeWishlist']);
        Route::post('wishlist', [StorefrontController::class, 'addWishlist']);
        Route::delete('wishlist/{id}', [StorefrontController::class, 'deleteWishlist']);
        Route::post('reviews', [StorefrontController::class, 'createReview']);
            Route::get('support/tickets', [EngagementController::class, 'tickets']);
        Route::post('support/tickets', [EngagementController::class, 'createTicket']);
        Route::get('support/tickets/{id}', [EngagementController::class, 'showTicket']);
        Route::post('support/tickets/{id}/messages', [EngagementController::class, 'replyTicket']);
        Route::post('payments/intents', [PaymentSimulationController::class, 'createIntent']);
            Route::post('payments/intents/{id}/confirm', [PaymentSimulationController::class, 'confirmIntent']);
            Route::get('payments/transactions', [PaymentSimulationController::class, 'customerTransactions']);
            Route::post('refunds', [PaymentSimulationController::class, 'requestRefund']);
            Route::get('refunds', [PaymentSimulationController::class, 'customerRefunds']);

        });

        Route::middleware('role:Admin|Super Admin')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::post('admin/orders/{orderId}/shipment', [StorefrontController::class, 'upsertShipment']);
            Route::get('admin/audit-logs', [AuditLogController::class, 'index']);
            Route::get('admin/coupons', [CouponManagementController::class, 'index']);
            Route::post('admin/coupons', [CouponManagementController::class, 'store']);
            Route::patch('admin/coupons/{id}', [CouponManagementController::class, 'update']);
            Route::patch('admin/coupons/{id}/status', [CouponManagementController::class, 'updateStatus']);
            Route::delete('admin/coupons/{id}', [CouponManagementController::class, 'destroy']);
            Route::get('admin/brands', [CatalogManagementController::class, 'brands']);
            Route::post('admin/brands', [CatalogManagementController::class, 'storeBrand']);
            Route::get('admin/brands/{id}', [CatalogManagementController::class, 'brand']);
            Route::patch('admin/brands/{id}', [CatalogManagementController::class, 'updateBrand']);
            Route::delete('admin/brands/{id}', [CatalogManagementController::class, 'deleteBrand']);
            Route::get('admin/categories', [CatalogManagementController::class, 'categories']);
            Route::post('admin/categories', [CatalogManagementController::class, 'storeCategory']);
            Route::get('admin/categories/{id}', [CatalogManagementController::class, 'category']);
            Route::patch('admin/categories/{id}', [CatalogManagementController::class, 'updateCategory']);
            Route::delete('admin/categories/{id}', [CatalogManagementController::class, 'deleteCategory']);
            Route::get('admin/marketplace-settings', [CatalogManagementController::class, 'marketplaceSettings']);
            Route::post('admin/collections', [CatalogManagementController::class, 'storeCollection']);
            Route::patch('admin/collections/{id}', [CatalogManagementController::class, 'updateCollection']);
            Route::patch('admin/collections/{id}/status', [CatalogManagementController::class, 'updateCollectionStatus']);
            Route::post('admin/shipping-methods', [CatalogManagementController::class, 'storeShippingMethod']);
            Route::patch('admin/shipping-methods/{id}', [CatalogManagementController::class, 'updateShippingMethod']);
            Route::patch('admin/shipping-methods/{id}/status', [CatalogManagementController::class, 'updateShippingMethodStatus']);
            Route::get('admin/reviews', [ReviewManagementController::class, 'index']);
            Route::patch('admin/reviews/{id}', [ReviewManagementController::class, 'review']);
            Route::get('admin/catalog-settings', [CatalogManagementController::class, 'catalogSettings']);
            Route::post('admin/attributes', [CatalogManagementController::class, 'storeAttribute']);
            Route::patch('admin/attributes/{id}', [CatalogManagementController::class, 'updateAttribute']);
            Route::patch('admin/attributes/{id}/status', [CatalogManagementController::class, 'updateAttributeStatus']);
            Route::post('admin/tags', [CatalogManagementController::class, 'storeTag']);
            Route::patch('admin/tags/{id}', [CatalogManagementController::class, 'updateTag']);
            Route::patch('admin/tags/{id}/status', [CatalogManagementController::class, 'updateTagStatus']);
            Route::get('admin/catalog-requests', [CatalogRequestController::class, 'adminIndex']);
            Route::patch('admin/catalog-requests/{id}', [CatalogRequestController::class, 'review']);
            Route::get('admin/orders', [StorefrontController::class, 'adminOrders']);
            Route::get('admin/refunds', [PaymentSimulationController::class, 'adminRefunds']);
            Route::get('admin/support/tickets', [EngagementController::class, 'adminTickets']);
            Route::get('admin/support/tickets/{id}', [EngagementController::class, 'adminShowTicket']);
            Route::post('admin/support/tickets/{id}/messages', [EngagementController::class, 'adminReplyTicket']);
            Route::patch('admin/support/tickets/{id}', [EngagementController::class, 'updateTicket']);
            Route::get('admin/payouts', [VendorFinanceController::class, 'adminPayouts']);
            Route::patch('admin/payouts/{id}/review', [VendorFinanceController::class, 'reviewPayout']);
            Route::post('admin/payouts/{id}/process', [VendorFinanceController::class, 'processPayout']);
            Route::patch('admin/refunds/{id}/review', [PaymentSimulationController::class, 'reviewRefund']);
            Route::post('admin/refunds/{id}/process', [PaymentSimulationController::class, 'processRefund']);
            Route::get('admin/orders/{id}', [StorefrontController::class, 'adminOrder']);
            Route::patch('admin/orders/{orderId}/fulfillments/{fulfillmentId}', [StorefrontController::class, 'updateAdminFulfillment']);
            Route::get('admin/stores', [StorefrontController::class, 'adminStores']);
            Route::get('admin/stores/{id}', [StorefrontController::class, 'adminStore']);
            Route::patch('admin/stores/{id}', [StorefrontController::class, 'updateAdminStore']);
            Route::patch('admin/stores/{id}/status', [StorefrontController::class, 'updateStoreStatus']);
            Route::patch('admin/stores/{id}/commission', [StorefrontController::class, 'updateStoreCommission']);
            Route::get('admin/customers', [UserController::class, 'customers']);
            Route::get('admin/customers/{user}', [UserController::class, 'customer']);
            Route::get('admin/users/options', [UserController::class, 'options']);
            Route::apiResource('admin/users', UserController::class);
            Route::get('email-logs', [EmailLogController::class, 'index']);
            Route::get('email-logs/{emailLog}', [EmailLogController::class, 'show']);
            Route::delete('email-logs/{emailLog}', [EmailLogController::class, 'destroy']);
            Route::post('email-logs/{emailLog}/retry', [EmailLogController::class, 'retry']);
            Route::post('emails/send', [EmailController::class, 'send']);
            Route::get('admin/products/options', [VendorProductController::class, 'options']);
            Route::get('admin/products', [VendorProductController::class, 'adminIndex']);
            Route::get('admin/products/{id}', [VendorProductController::class, 'adminShow']);
            Route::patch('admin/products/{id}', [VendorProductController::class, 'updateAdminProduct']);
            Route::patch('admin/products/{id}/approval', [VendorProductController::class, 'updateApproval']);
            Route::patch('admin/products/{id}/status', [VendorProductController::class, 'updateAdminStatus']);
            Route::patch('admin/products/{id}/featured', [VendorProductController::class, 'updateFeatured']);
            // Keep the storefront admin contract on VendorProductController; expose
            // the lower-level product resource separately to avoid duplicate GET routes.
            Route::apiResource('catalog/products', ProductController::class);
            Route::apiResource('admin.products.variants', ProductVariantController::class);
        });

        // Only the super-admin role can change the authorization model itself.
        Route::middleware('role:Super Admin')->group(function () {
            Route::get('roles/options', [RoleController::class, 'options']);
            Route::apiResource('roles', RoleController::class);
            Route::get('permissions/options', [PermissionController::class, 'options']);
            Route::apiResource('permissions', PermissionController::class);
        });

        Route::post('ai/product-assistant', [ProductAssistantController::class, 'chat']);
        Route::post('ai/product-assistant/stream', [ProductAssistantController::class, 'stream']);
    });
});
