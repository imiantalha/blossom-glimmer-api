<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StorefrontDemoSeeder;
use Database\Seeders\StorefrontScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                'cart_item_id' => (string) $item->id,
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

}
