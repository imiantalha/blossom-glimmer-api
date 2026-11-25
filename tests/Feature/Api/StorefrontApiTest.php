<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StorefrontDemoSeeder;
use Database\Seeders\StorefrontScenarioSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['storefront.seed_demo_catalog' => true]);
        $this->seed(StorefrontDemoSeeder::class);
        $this->seed(StorefrontScenarioSeeder::class);
    }

    public function test_public_product_listing_returns_frontend_pagination_contract(): void
    {
        $this->getJson('/api/v1/products?per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'price', 'images', 'variants', 'category', 'brand']],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total', 'count', 'from', 'to', 'has_previous', 'has_next'],
            ]);
    }

    public function test_registration_returns_a_sanctum_session_and_frontend_user_shape(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'firstName' => 'Test',
            'lastName' => 'Customer',
            'email' => 'new-customer@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'role', 'roles', 'permissions', 'createdAt'], 'token', 'tokenType', 'expiresAt']]);
    }

    public function test_password_reset_request_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known-customer@example.test']);
        $genericMessage = 'If an account exists for this email, password reset instructions will be sent.';

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', $genericMessage);

        // A throttled request for a real account must not have a different response.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', $genericMessage);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown-customer@example.test'])
            ->assertOk()
            ->assertJsonPath('message', $genericMessage);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_customer_cannot_access_admin_routes(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('Customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_demo_customer_can_login_with_seeded_account(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@demo.blossomglimmer.local',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token', 'tokenType', 'expiresAt']]);
    }

    public function test_seeded_vendor_can_list_only_its_products(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/vendor/products?per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'status', 'variants', 'images']]]);
    }

    public function test_vendor_can_submit_product_and_admin_can_approve_it(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $brandId = DB::table('brands')->where('status', 'active')->value('id');
        $categoryId = DB::table('categories')->where('status', 'active')->value('id');

        DB::table('tags')->updateOrInsert(['slug' => 'api-test'], ['name' => 'api-test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/vendor/products', [
            'name' => 'API Test Simple Product',
            'slug' => 'api-test-simple-product',
            'brandId' => $brandId,
            'categoryId' => $categoryId,
            'productType' => 'Simple',
            'shippingProfileId' => 'standard',
            'shortDescription' => 'A test product.',
            'fullDescription' => 'Created through the vendor storefront API.',
            'tags' => ['api-test'],
            'media' => ['featuredImage' => ['url' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30', 'altText' => 'Test product', 'sortOrder' => 0], 'galleryImages' => []],
            'attributes' => [],
            'variants' => [[
                'label' => 'Default', 'sku' => 'API-TEST-SIMPLE-001', 'price' => 25,
                'stockQuantity' => 8, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
                'images' => [], 'attributeValues' => [],
            ]],
            'specifications' => [], 'faqs' => [], 'intent' => 'submit',
        ])->assertCreated()->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.approvalStatus', 'PENDING');

        $productId = $response->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/products/' . $productId . '/status', ['status' => 'active'])
            ->assertUnprocessable();

        $this->patchJson('/api/v1/admin/products/' . $productId . '/approval', ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.approvalStatus', 'APPROVED');

        $this->assertDatabaseHas('inventory_items', ['product_id' => $productId, 'stock_quantity' => 8]);
    }

    public function test_vendor_cannot_access_another_vendors_product(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $otherProduct = Product::where('vendor_id', '<>', $vendor->id)->firstOrFail();

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/vendor/products/' . $otherProduct->id)
            ->assertNotFound();
    }

    public function test_demo_shipping_and_storefront_scenarios_are_seeded(): void
    {
        $this->assertDatabaseCount('vendor_profiles', 4);
        $this->assertDatabaseCount('shipping_methods', 2);
        $this->assertDatabaseHas('customer_orders', ['number' => 'BG-DEMO-1001']);
        $this->assertDatabaseHas('shipments', ['tracking_number' => 'BG-DEMO-TRACK-1001']);
        $this->assertGreaterThanOrEqual(2, DB::table('products')->where('type', 'simple')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('products')->where('type', 'variable')->count());
    }

    public function test_inventory_validation_and_checkout_idempotency_use_seeded_data(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $cart = DB::table('carts')->where('user_id', $customer->id)->firstOrFail();
        $item = DB::table('cart_items')->where('cart_id', $cart->id)->firstOrFail();
        $addressId = DB::table('customer_addresses')->where('user_id', $customer->id)->value('id');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/inventory/validate', ['items' => [[
                'cart_item_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.authoritative', true);

        $payload = ['address_id' => $addressId, 'shipping_method_id' => 'standard', 'payment_method' => 'cod'];
        $first = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'feature-test-checkout-001'])
            ->assertCreated()
            ->assertJsonPath('success', true);
        $orderId = $first->json('data.id');

        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'feature-test-checkout-001'])
            ->assertOk()
            ->assertJsonPath('data.id', $orderId);

        $this->assertDatabaseCount('customer_orders', 2);
    }

    public function test_authenticated_customer_receives_a_persistent_empty_cart(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('Customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items', [])
            ->assertJsonStructure(['data' => ['id', 'currency', 'items', 'subtotal', 'total']]);
    }

    public function test_admin_can_create_brands_and_categories(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/brands', [
                'name' => 'Integration Brand',
                'slug' => 'integration-brand',
                'status' => 'ACTIVE',
                'logo' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'integration-brand')
            ->assertJsonPath('data.status', 'ACTIVE');

        $root = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/categories', [
                'name' => 'Integration Root',
                'slug' => 'integration-root',
                'status' => 'ACTIVE',
            ])
            ->assertCreated()
            ->assertJsonPath('data.parentId', null);

        $rootId = $root->json('data.id');
        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Integration Child',
            'slug' => 'integration-child',
            'status' => 'ACTIVE',
            'parent_id' => $rootId,
        ])->assertCreated()->assertJsonPath('data.parentId', (string) $rootId);

        $this->getJson('/api/v1/admin/categories')->assertOk()
            ->assertJsonFragment(['slug' => 'integration-root'])
            ->assertJsonFragment(['slug' => 'integration-child']);
    }

    public function test_demo_seeders_preserve_non_demo_catalog_and_vendor_credentials(): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Manually Managed Category', 'slug' => 'manual-managed-category',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $brandId = DB::table('brands')->insertGetId([
            'name' => 'Manually Managed Brand', 'slug' => 'manual-managed-brand',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Manually Managed Product', 'slug' => 'manual-managed-product',
            'type' => 'simple', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customVendor = User::factory()->create(['email' => 'independent-vendor@example.test']);
        $customVendor->assignRole('Vendor');
        DB::table('vendor_profiles')->insert([
            'user_id' => $customVendor->id, 'slug' => 'independent-vendor',
            'name' => 'Independent Vendor', 'status' => 'active',
            'commission_type' => 'percentage', 'commission_value' => 10,
            'review_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $originalPassword = $customVendor->password;

        $this->seed(StorefrontDemoSeeder::class);
        $this->seed(StorefrontScenarioSeeder::class);

        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'status' => 'active']);
        $this->assertDatabaseHas('brands', ['id' => $brandId, 'status' => 'active']);
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'active']);
        $this->assertSame($originalPassword, $customVendor->fresh()->password);
    }

    public function test_non_admin_cannot_manage_catalog_taxonomy(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('Customer');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/admin/brands', ['name' => 'No Access', 'status' => 'ACTIVE'])
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/categories')
            ->assertForbidden();
    }

    public function test_seeded_customer_and_vendor_accounts_can_login_with_normalized_emails(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'CUSTOMER@DEMO.BLOSSOMGlimmer.LOCAL',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'customer@demo.blossomglimmer.local');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'VENDOR+ATELIER-LANE@DEMO.BLOSSOMGlimmer.LOCAL',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'vendor+atelier-lane@demo.blossomglimmer.local');
    }

    public function test_login_rejects_invalid_credentials_without_creating_a_token(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();

        $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'WrongPassword123!',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_the_current_sanctum_token(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $plainTextToken = $customer->createToken('logout-regression')->plainTextToken;

        $this->withToken($plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $customer->id,
            'name' => 'logout-regression',
        ]);

        // The test application reuses resolved guards between HTTP calls; reset
        // them so this request authenticates against the now-revoked token.
        Auth::forgetGuards();
        $this->withToken($plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_customer_cannot_access_vendor_product_management(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/vendor/products')
            ->assertForbidden();
    }

    public function test_customer_address_crud_is_scoped_to_the_authenticated_owner(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $otherCustomer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();

        $created = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/users/me/addresses', [
            'firstName' => 'Test', 'lastName' => 'Customer', 'address1' => '42 Test Street',
            'city' => 'Lahore', 'state' => 'Punjab', 'postalCode' => '54000',
            'country' => 'us', 'phone' => '+923001112233', 'isDefault' => true,
        ])->assertCreated()->assertJsonPath('data.country', 'US');

        $addressId = $created->json('data.id');

        $this->actingAs($otherCustomer, 'sanctum')
            ->patchJson('/api/v1/users/me/addresses/' . $addressId, ['city' => 'Karachi'])
            ->assertNotFound();

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/users/me/addresses/' . $addressId, ['city' => 'Islamabad'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Islamabad');

        $this->actingAs($customer, 'sanctum')
            ->deleteJson('/api/v1/users/me/addresses/' . $addressId)
            ->assertOk();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $addressId]);
    }

    public function test_customer_cart_supports_add_update_and_clear_with_inventory_checks(): void
    {
        $customer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $product = DB::table('products as p')
            ->join('inventory_items as i', 'i.product_id', '=', 'p.id')
            ->whereNull('i.variant_id')
            ->where('p.type', 'simple')
            ->where('p.status', 'active')
            ->whereRaw('i.stock_quantity - i.reserved_quantity >= ?', [5])
            ->select('p.id')
            ->first();

        $this->assertNotNull($product);

        $added = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/cart', [
            'product_id' => $product->id, 'quantity' => 2,
        ])->assertOk();

        $this->assertSame(2, (int) $added->json('data.items.0.quantity'));
        $itemId = $added->json('data.items.0.id');

        $updated = $this->patchJson('/api/v1/cart/' . $itemId, ['quantity' => 3])->assertOk();
        $this->assertSame(3, (int) $updated->json('data.items.0.quantity'));

        $this->patchJson('/api/v1/cart/' . $itemId, ['quantity' => 100])
            ->assertUnprocessable();

        $this->deleteJson('/api/v1/cart')
            ->assertOk()->assertJsonPath('data.items', []);

        $this->assertDatabaseMissing('cart_items', ['id' => $itemId]);
    }

    public function test_customer_wishlist_is_idempotent_and_owner_scoped(): void
    {
        $customer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $otherCustomer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('status', 'active')->where('type', 'simple')->orderByDesc('id')->firstOrFail();

        $first = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/wishlist', [
            'product_id' => $product->id,
        ])->assertCreated();
        $wishlistId = $first->json('data.id');

        $this->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();
        $this->assertSame(1, DB::table('wishlists')->where('user_id', $customer->id)->where('product_id', $product->id)->count());

        $this->actingAs($otherCustomer, 'sanctum')
            ->deleteJson('/api/v1/wishlist/' . $wishlistId)
            ->assertOk();

        $this->assertDatabaseHas('wishlists', ['id' => $wishlistId, 'user_id' => $customer->id]);

        $this->actingAs($customer, 'sanctum')
            ->deleteJson('/api/v1/wishlist/' . $wishlistId)
            ->assertOk();

        $this->assertDatabaseMissing('wishlists', ['id' => $wishlistId]);
    }

    public function test_shipping_quote_is_address_owned_and_uses_seeded_methods(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $otherCustomer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $addressId = DB::table('customer_addresses')->where('user_id', $customer->id)->value('id');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/shipping/quote', ['address_id' => $addressId, 'currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.source', 'live')
            ->assertJsonCount(2, 'data.methods');

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson('/api/v1/shipping/quote', ['address_id' => $addressId, 'currency' => 'USD'])
            ->assertNotFound();
    }

    public function test_vendor_can_create_store_and_admin_can_moderate_it(): void
    {
        $vendor = User::factory()->create(['email' => 'new-vendor@example.test']);
        $vendor->assignRole('Vendor');
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();

        $created = $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/vendor/store', [
            'name' => 'Feature Test Store',
            'slug' => 'feature-test-store-' . $vendor->id,
            'description' => 'A store created by the API feature test.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $storeId = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/stores/' . $storeId . '/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->patchJson('/api/v1/admin/stores/' . $storeId . '/commission', [
            'commission_type' => 'percentage', 'commission_value' => 12.5,
        ])->assertOk()->assertJsonPath('data.commissionValue', 12.5);

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/vendor/store/me')
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_customer_cannot_read_another_customers_order(): void
    {
        $owner = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $otherCustomer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $orderId = DB::table('customer_orders')->where('user_id', $owner->id)->where('number', 'BG-DEMO-1001')->value('id');

        $this->assertNotNull($orderId);

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson('/api/v1/orders/' . $orderId)
            ->assertNotFound();
    }

    public function test_customer_review_is_saved_as_pending_moderation(): void
    {
        $customer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('status', 'active')->where('type', 'simple')->orderByDesc('id')->firstOrFail();

        $review = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/reviews', [
            'product_id' => $product->id, 'rating' => 4,
            'title' => 'Feature test review', 'body' => 'This review must wait for moderation.',
        ])->assertCreated();

        $this->assertSame(4, (int) $review->json('data.rating'));

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id, 'user_id' => $customer->id, 'status' => 'pending',
        ]);
    }

    public function test_seeded_admin_can_login_with_admin_role(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@demo.blossomglimmer.local',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_vendor_registration_creates_a_vendor_scoped_account(): void
    {
        $this->postJson('/api/v1/auth/register/vendor', [
            'firstName' => 'New', 'lastName' => 'Seller',
            'email' => 'NEW-SELLER@EXAMPLE.TEST',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'vendor')
            ->assertJsonPath('data.user.email', 'new-seller@example.test');

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => User::where('email', 'new-seller@example.test')->value('id'),
            'role_id' => DB::table('roles')->where('name', 'Vendor')->value('id'),
        ]);
    }

    public function test_password_change_keeps_current_token_and_revokes_other_sessions(): void
    {
        $customer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $currentToken = $customer->createToken('current-session')->plainTextToken;
        $otherToken = $customer->createToken('other-session')->plainTextToken;

        $this->withToken($currentToken)->patchJson('/api/v1/users/me/password', [
            'current_password' => 'Password123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $customer->id, 'name' => 'current-session',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $customer->id, 'name' => 'other-session',
        ]);

        Auth::forgetGuards();
        $this->withToken($currentToken)->getJson('/api/v1/auth/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check('NewPassword123!', $customer->fresh()->password));
    }

    public function test_public_catalog_and_storefront_read_endpoints_return_success(): void
    {
        $productSlug = DB::table('products')->where('status', 'active')->value('slug');
        $categorySlug = DB::table('categories')->where('status', 'active')->value('slug');
        $brandSlug = DB::table('brands')->where('status', 'active')->value('slug');
        $vendorSlug = DB::table('vendor_profiles')->where('status', 'active')->value('slug');
        $collectionSlug = DB::table('collections')->where('is_active', true)->value('slug');

        $paths = [
            '/api/v1/products',
            '/api/v1/products/' . $productSlug,
            '/api/v1/categories',
            '/api/v1/categories/' . $categorySlug,
            '/api/v1/brands',
            '/api/v1/brands/' . $brandSlug,
            '/api/v1/brands/' . $brandSlug . '/products',
            '/api/v1/vendors',
            '/api/v1/vendors/' . $vendorSlug,
            '/api/v1/vendors/' . $vendorSlug . '/products',
            '/api/v1/collections',
            '/api/v1/collections/' . $collectionSlug,
            '/api/v1/collections/' . $collectionSlug . '/products',
            '/api/v1/reviews',
            '/api/v1/shipping/methods',
            '/api/v1/payment/methods',
        ];

        foreach ($paths as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $product = $this->getJson('/api/v1/products/' . $productSlug)->assertOk();
        $vendorRating = $product->json('data.vendor.rating');
        if ($vendorRating !== null) {
            $this->assertIsFloat($vendorRating);
        }
    }

    public function test_admin_read_endpoints_are_available_to_admin_accounts(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();

        $paths = [
            '/api/v1/dashboard',
            '/api/v1/admin/brands',
            '/api/v1/admin/categories',
            '/api/v1/admin/stores',
            '/api/v1/admin/users/options',
            '/api/v1/admin/users',
            '/api/v1/roles/options',
            '/api/v1/roles',
            '/api/v1/permissions/options',
            '/api/v1/permissions',
            '/api/v1/email-logs',
            '/api/v1/admin/products/options',
            '/api/v1/admin/products',
        ];

        foreach ($paths as $path) {
            $response = $this->actingAs($admin, 'sanctum')->getJson($path);
            $this->assertSame(200, $response->status(), "GET {$path} failed: {$response->getContent()}");
            $response->assertJsonPath('success', true);
        }

        $product = Product::firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/products/' . $product->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['store' => ['owner' => ['id', 'firstName', 'lastName', 'email']]]]);
    }

    public function test_guest_cart_and_wishlist_merge_into_authenticated_customer_account(): void
    {
        $customer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $product = DB::table('products as p')
            ->join('inventory_items as i', 'i.product_id', '=', 'p.id')
            ->whereNull('i.variant_id')
            ->where('p.type', 'simple')
            ->where('p.status', 'active')
            ->whereRaw('i.stock_quantity - i.reserved_quantity >= ?', [2])
            ->select('p.id', 'p.slug')
            ->first();

        $this->assertNotNull($product);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/cart/merge', [
                'items' => [['product_slug' => $product->slug, 'quantity' => 2]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->postJson('/api/v1/wishlist/merge', [
            'product_slugs' => [$product->slug],
        ])->assertOk()->assertJsonPath('data.merged', 1);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_admin_can_manage_custom_roles_and_permissions(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();

        $permission = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/permissions', ['name' => 'reports.view'])
            ->assertCreated()
            ->json('data');

        $role = $this->postJson('/api/v1/roles', [
            'name' => 'Report Viewer',
            'permissions' => ['reports.view'],
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role['id'],
            'permission_id' => $permission['id'],
        ]);

        $createdRole = \Spatie\Permission\Models\Role::findOrFail($role['id']);
        $this->assertSame('web', $createdRole->guard_name);
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(User::class, \Spatie\Permission\Guard::getModelForGuard('web'));

        $this->patchJson('/api/v1/permissions/' . $permission['id'], [
            'name' => 'reports.manage',
        ])->assertOk();

        $this->deleteJson('/api/v1/roles/' . $role['id'])->assertOk();
        $this->deleteJson('/api/v1/permissions/' . $permission['id'])->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role['id']]);
        $this->assertDatabaseMissing('permissions', ['id' => $permission['id']]);
    }

    public function test_admin_shipment_updates_are_visible_in_customer_order_history_and_details(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $otherCustomer = User::where('email', 'customer2@demo.blossomglimmer.local')->firstOrFail();
        $orderId = DB::table('customer_orders')
            ->where('user_id', $customer->id)
            ->where('number', 'BG-DEMO-1001')
            ->value('id');

        $this->assertNotNull($orderId);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/admin/orders/' . $orderId . '/shipment', [
                'status' => 'in_transit',
                'tracking_number' => 'BG-REGRESSION-TRACK-1001',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders/' . $orderId . '/shipment', [
                'carrier' => 'Demo Carrier',
                'tracking_number' => 'BG-REGRESSION-TRACK-1001',
                'tracking_url' => 'https://tracking.example.test/BG-REGRESSION-TRACK-1001',
                'status' => 'in_transit',
                'description' => 'Package is moving through the network.',
                'location' => 'Lahore',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.trackingNumber', 'BG-REGRESSION-TRACK-1001');

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => DB::table('shipments')->where('order_id', $orderId)->value('id'),
            'status' => 'in_transit',
            'location' => 'Lahore',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['number' => 'BG-DEMO-1001'])
            ->assertJsonFragment(['trackingNumber' => 'BG-REGRESSION-TRACK-1001']);

        $this->getJson('/api/v1/orders/' . $orderId)
            ->assertOk()
            ->assertJsonPath('data.shipment.status', 'in_transit')
            ->assertJsonPath('data.shipment.trackingUrl', 'https://tracking.example.test/BG-REGRESSION-TRACK-1001');

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson('/api/v1/orders/' . $orderId)
            ->assertNotFound();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders/' . $orderId . '/shipment', [
                'status' => 'delivered',
                'delivered_at' => now()->toISOString(),
                'description' => 'Delivered to recipient.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseHas('customer_orders', [
            'id' => $orderId,
            'status' => 'delivered',
            'fulfillment_status' => 'delivered',
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => DB::table('shipments')->where('order_id', $orderId)->value('id'),
            'status' => 'delivered',
        ]);
    }


    public function test_checkout_rejects_insufficient_stock_without_creating_an_order_or_clearing_cart(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $cart = DB::table('carts')->where('user_id', $customer->id)->firstOrFail();
        $item = DB::table('cart_items')->where('cart_id', $cart->id)->firstOrFail();
        $addressId = DB::table('customer_addresses')->where('user_id', $customer->id)->value('id');
        $inventoryQuery = DB::table('inventory_items')->where(
            $item->variant_id ? 'variant_id' : 'product_id',
            $item->variant_id ?? $item->product_id
        );
        $inventory = $inventoryQuery->first();

        $this->assertNotNull($inventory);
        $this->assertNotNull($addressId);

        DB::table('inventory_items')->where('id', $inventory->id)->update([
            'stock_quantity' => $inventory->reserved_quantity,
        ]);

        $orderCount = DB::table('customer_orders')->count();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/orders', [
                'address_id' => $addressId,
                'shipping_method_id' => 'standard',
                'payment_method' => 'cod',
            ], ['Idempotency-Key' => 'insufficient-stock-regression'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame($orderCount, DB::table('customer_orders')->count());
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'cart_id' => $cart->id]);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventory->id,
            'stock_quantity' => $inventory->reserved_quantity,
        ]);
    }


    public function test_vendor_inventory_updates_preserve_reserved_stock(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('vendor_id', $vendor->id)->where('type', 'simple')->firstOrFail();
        $inventory = DB::table('inventory_items')->where('product_id', $product->id)->whereNull('variant_id')->first();

        $this->assertNotNull($inventory);
        DB::table('inventory_items')->where('id', $inventory->id)->update(['stock_quantity' => 8, 'reserved_quantity' => 3]);

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/vendor/products/' . $product->id . '/inventory', ['stock_quantity' => 10])
            ->assertOk()
            ->assertJsonPath('data.reservedQuantity', 3)
            ->assertJsonPath('data.availableQuantity', 7);

        $this->assertDatabaseHas('inventory_items', ['id' => $inventory->id, 'stock_quantity' => 10, 'reserved_quantity' => 3]);

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/vendor/products/' . $product->id . '/inventory', ['stock_quantity' => 2])
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventory_items', ['id' => $inventory->id, 'stock_quantity' => 10, 'reserved_quantity' => 3]);
    }

    public function test_vendor_cannot_update_another_vendors_inventory(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $otherProduct = Product::where('vendor_id', '<>', $vendor->id)->where('type', 'simple')->firstOrFail();

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/vendor/products/' . $otherProduct->id . '/inventory', ['stock_quantity' => 99])
            ->assertNotFound();
    }


    public function test_vendor_product_edits_preserve_existing_variant_ids_and_reserved_inventory(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('vendor_id', $vendor->id)
            ->where('type', 'variable')
            ->with(['variants.inventoryItems'])
            ->firstOrFail();
        $categoryId = $product->categories()->value('categories.id');
        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $inventory = $variant->inventoryItems->first();
        $this->assertNotNull($inventory);

        DB::table('inventory_items')->where('id', $inventory->id)->update([
            'stock_quantity' => max(8, (int) $inventory->stock_quantity),
            'reserved_quantity' => 2,
        ]);

        $images = data_get($product->metadata, 'images', []);
        $featuredImage = $images[0] ?? [
            'url' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30',
            'altText' => 'Product image',
        ];
        $variants = $product->variants->map(function ($item) {
            $itemInventory = $item->inventoryItems->first();
            $attributes = data_get($item->metadata, 'attributes', []);

            return [
                'sku' => $item->sku,
                'price' => (float) ($item->compare_at_price ?? $item->price),
                'salePrice' => $item->compare_at_price ? (float) $item->price : null,
                'stockQuantity' => max(8, (int) ($itemInventory?->stock_quantity ?? 8)),
                'weight' => (float) data_get($item->metadata, 'weight', 1),
                'length' => (float) data_get($item->metadata, 'length', 1),
                'width' => (float) data_get($item->metadata, 'width', 1),
                'height' => (float) data_get($item->metadata, 'height', 1),
                'label' => $item->name,
                'images' => data_get($item->metadata, 'images', []),
                'attributeValues' => collect($attributes)->map(
                    fn ($value, $name) => ['attributeName' => $name, 'value' => $value]
                )->values()->all(),
            ];
        })->values()->all();

        $payload = [
            'name' => $product->name . ' Updated',
            'slug' => $product->slug,
            'brandId' => $product->brand_id,
            'categoryId' => $categoryId,
            'productType' => 'Variable',
            'shippingProfileId' => 'standard',
            'shortDescription' => $product->short_description,
            'fullDescription' => $product->description,
            'tags' => data_get($product->metadata, 'tags', []),
            'media' => [
                'featuredImage' => $featuredImage,
                'galleryImages' => array_slice($images, 1),
            ],
            'attributes' => data_get($product->metadata, 'attributes', []),
            'variants' => $variants,
            'specifications' => data_get($product->metadata, 'specifications', []),
            'faqs' => data_get($product->metadata, 'faqs', []),
            'intent' => 'submit',
        ];

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/vendor/products/' . $product->id, $payload)
            ->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'product_id' => $product->id,
            'sku' => $variant->sku,
        ]);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventory->id,
            'variant_id' => $variant->id,
            'reserved_quantity' => 2,
        ]);
    }



    public function test_admin_can_filter_products_and_manage_visibility_and_featured_state(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('status', 'active')->firstOrFail();
        $metadata = $product->metadata ?? [];
        $metadata['approvalStatus'] = 'approved';
        $product->update(['metadata' => $metadata]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/products?' . http_build_query(['approval_status' => 'approved', 'status' => 'active', 'name' => $product->name]))
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $product->id]);

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive']);

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/featured', ['is_featured' => true])
            ->assertOk()->assertJsonPath('data.isFeatured', true);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_featured' => true]);

        $pending = Product::where('status', 'inactive')->first();
        if ($pending) {
            $pendingMetadata = $pending->metadata ?? [];
            $pendingMetadata['approvalStatus'] = 'pending';
            $pending->update(['metadata' => $pendingMetadata]);
            $this->patchJson('/api/v1/admin/products/' . $pending->id . '/status', ['status' => 'active'])
                ->assertUnprocessable();
        }
    }

    public function test_suspending_a_store_hides_its_products_and_blocks_vendor_mutations(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $profile = $vendor->vendorProfile()->firstOrFail();
        $product = Product::where('vendor_id', $vendor->id)->where('status', 'active')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/stores/' . $profile->id . '/status', ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->getJson('/api/v1/vendors/' . $profile->slug)->assertNotFound();

        $catalog = $this->getJson('/api/v1/products')->assertOk()->json('data');
        $this->assertFalse(collect($catalog)->contains(fn ($item) => (string) $item['id'] === (string) $product->id));

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/vendor/products/' . $product->id, [])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/stores/' . $profile->id . '/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_non_admin_cannot_change_product_or_store_moderation_state(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::firstOrFail();
        $profileId = DB::table('vendor_profiles')->value('id');

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/admin/products/' . $product->id . '/status', ['status' => 'inactive'])
            ->assertForbidden();

        $this->patchJson('/api/v1/admin/stores/' . $profileId . '/status', ['status' => 'suspended'])
            ->assertForbidden();
    }



    public function test_admin_product_rejection_requires_and_persists_vendor_feedback(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('status', 'active')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/products/' . $product->id . '/approval', ['decision' => 'reject'])
            ->assertUnprocessable();

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/approval', [
            'decision' => 'reject',
            'reason' => 'Please provide clearer product images and complete the material details.',
        ])->assertOk()
            ->assertJsonPath('data.approvalStatus', 'REJECTED')
            ->assertJsonPath('data.rejectionReason', 'Please provide clearer product images and complete the material details.');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive']);

        $vendor = User::findOrFail($product->vendor_id);
        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/vendor/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('data.rejectionReason', 'Please provide clearer product images and complete the material details.');
    }



    public function test_legacy_active_product_without_approval_metadata_can_be_deactivated_and_reactivated(): void
    {
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();
        $product = Product::where('status', 'active')->firstOrFail();
        $metadata = $product->metadata ?? [];
        unset($metadata['approvalStatus']);
        $product->update(['metadata' => $metadata]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/products?' . http_build_query(['status' => 'active', 'approval_status' => 'approved', 'name' => $product->name]))
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $product->id]);

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.approvalStatus', 'APPROVED');

        $this->patchJson('/api/v1/admin/products/' . $product->id . '/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    }


    public function test_vendor_can_submit_brand_and_category_requests_and_admin_can_approve_them(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();

        $brandRequest = $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/vendor/catalog-requests', [
                'type' => 'brand',
                'name' => 'Vendor Requested Brand',
                'slug' => 'vendor-requested-brand',
                'logo' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800',
            ])->assertCreated()
            ->assertJsonPath('data.type', 'brand')
            ->assertJsonPath('data.status', 'pending');

        $brandRequestId = $brandRequest->json('data.id');

        $categoryRequest = $this->postJson('/api/v1/vendor/catalog-requests', [
            'type' => 'category',
            'name' => 'Vendor Requested Category',
            'slug' => 'vendor-requested-category',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $categoryRequestId = $categoryRequest->json('data.id');

        $this->getJson('/api/v1/vendor/catalog-requests')
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $brandRequestId])
            ->assertJsonFragment(['id' => (string) $categoryRequestId]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/catalog-requests?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $brandRequestId]);

        $this->patchJson('/api/v1/admin/catalog-requests/' . $brandRequestId, [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.entity.type', 'brand');

        $this->patchJson('/api/v1/admin/catalog-requests/' . $categoryRequestId, [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.entity.type', 'category');

        $this->assertDatabaseHas('brands', ['slug' => 'vendor-requested-brand', 'status' => 'active']);
        $this->assertDatabaseHas('categories', ['slug' => 'vendor-requested-category', 'status' => 'active']);

        $this->getJson('/api/v1/brands')->assertOk()->assertJsonFragment(['slug' => 'vendor-requested-brand']);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonFragment(['slug' => 'vendor-requested-category']);
    }

    public function test_admin_must_provide_a_reason_when_rejecting_a_catalog_request(): void
    {
        $vendor = User::where('email', 'vendor+atelier-lane@demo.blossomglimmer.local')->firstOrFail();
        $admin = User::where('email', 'admin@demo.blossomglimmer.local')->firstOrFail();

        $request = $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/vendor/catalog-requests', [
                'type' => 'brand',
                'name' => 'Rejected Vendor Brand',
                'slug' => 'rejected-vendor-brand',
            ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/catalog-requests/' . $request->json('data.id'), [
                'decision' => 'reject',
            ])->assertUnprocessable();

        $this->patchJson('/api/v1/admin/catalog-requests/' . $request->json('data.id'), [
            'decision' => 'reject',
            'rejection_reason' => 'Please provide an authorized brand logo.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Please provide an authorized brand logo.');

        $this->assertDatabaseMissing('brands', ['slug' => 'rejected-vendor-brand']);
    }

    public function test_customer_cannot_submit_or_review_catalog_requests(): void
    {
        $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/vendor/catalog-requests', [
                'type' => 'brand',
                'name' => 'Unauthorized Brand',
            ])->assertForbidden();

        $this->patchJson('/api/v1/admin/catalog-requests/1', [
            'decision' => 'approve',
        ])->assertForbidden();
    }


    public function test_admin_can_manage_approved_product_attributes_and_tags(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $attribute = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/attributes', [
                'name' => 'Material',
                'slug' => 'material',
                'type' => 'select',
                'is_variant_attribute' => true,
                'values' => ['Cotton', 'Linen'],
            ])->assertCreated()
            ->assertJsonPath('data.slug', 'material')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonCount(2, 'data.values');

        $tag = $this->postJson('/api/v1/admin/tags', [
            'name' => 'Handmade',
            'slug' => 'handmade',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE');

        $options = $this->getJson('/api/v1/admin/products/options')->assertOk();
        $options->assertJsonFragment(['name' => 'Material']);
        $options->assertJsonFragment(['name' => 'Handmade']);

        $this->patchJson('/api/v1/admin/attributes/' . $attribute->json('data.id') . '/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->patchJson('/api/v1/admin/tags/' . $tag->json('data.id') . '/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');

        $inactiveOptions = $this->getJson('/api/v1/admin/products/options')->assertOk();
        $this->assertNotContains('Material', collect($inactiveOptions->json('data.attributes'))->pluck('name')->all());
        $this->assertNotContains('Handmade', collect($inactiveOptions->json('data.tags'))->pluck('name')->all());
    }

}
