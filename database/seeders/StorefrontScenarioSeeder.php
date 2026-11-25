<?php

namespace Database\Seeders;

use App\Constants\Role as RoleConstant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StorefrontScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (!config('storefront.seed_demo_catalog')) return;

        DB::transaction(function (): void {
            foreach ([
                ['email' => 'customer@demo.blossomglimmer.local', 'name' => 'Demo Customer'],
                ['email' => 'customer2@demo.blossomglimmer.local', 'name' => 'Demo Customer Two'],
                ['email' => 'admin@demo.blossomglimmer.local', 'name' => 'Demo Store Admin'],
            ] as $account) {
                $user = User::updateOrCreate(
                    ['email' => $account['email']],
                    ['name' => $account['name'], 'password' => Hash::make('Password123!'), 'phone' => '+923001234567']
                );
                $user->syncRoles([$account['email'] === 'admin@demo.blossomglimmer.local' ? RoleConstant::ADMIN : RoleConstant::CUSTOMER]);
            }

            // Predictable development-only credentials for the seeded vendor stores.
            $vendorIds = DB::table('vendor_profiles')->pluck('user_id')->all();
            $vendors = User::whereIn('id', $vendorIds)->get();
            foreach ($vendors as $vendor) {
                $vendor->forceFill(['password' => Hash::make('Password123!')])->save();
            }

            DB::table('shipping_methods')->updateOrInsert(
                ['code' => 'standard'],
                ['name' => 'Standard delivery', 'description' => 'Tracked standard delivery for demo orders.', 'price' => 0, 'currency' => 'USD', 'estimated_delivery' => '3–7 business days', 'countries' => json_encode(['PK']), 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('shipping_methods')->updateOrInsert(
                ['code' => 'express'],
                ['name' => 'Express delivery', 'description' => 'Priority tracked delivery for demo orders.', 'price' => 12, 'currency' => 'USD', 'estimated_delivery' => '1–3 business days', 'countries' => json_encode(['PK']), 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]
            );

            $customer = User::where('email', 'customer@demo.blossomglimmer.local')->firstOrFail();
            $addressId = DB::table('customer_addresses')->where('user_id', $customer->id)->where('address1', '123 Demo Market Road')->value('id');
            if (!$addressId) {
                $addressId = DB::table('customer_addresses')->insertGetId([
                    'user_id' => $customer->id, 'first_name' => 'Demo', 'last_name' => 'Customer',
                    'address1' => '123 Demo Market Road', 'address2' => 'Suite 4', 'city' => 'Lahore',
                    'state' => 'Punjab', 'postal_code' => '54000', 'country' => 'PK',
                    'phone' => '+923001234567', 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $cartId = DB::table('carts')->where('user_id', $customer->id)->value('id');
            if (!$cartId) $cartId = DB::table('carts')->insertGetId(['user_id' => $customer->id, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]);

            $simple = DB::table('products as p')->join('inventory_items as i', 'i.product_id', '=', 'p.id')
                ->where('p.status', 'active')->where('p.type', 'simple')->whereRaw('i.stock_quantity > i.reserved_quantity')
                ->orderBy('p.id')->select('p.*')->first();
            $variable = DB::table('products')->where('status', 'active')->where('type', 'variable')->orderBy('id')->first();
            if ($simple) {
                DB::table('cart_items')->updateOrInsert(
                    ['cart_id' => $cartId, 'product_id' => $simple->id, 'variant_id' => null],
                    ['quantity' => 1, 'created_at' => now(), 'updated_at' => now()]
                );
                DB::table('wishlists')->updateOrInsert(
                    ['user_id' => $customer->id, 'product_id' => $simple->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
                DB::table('product_reviews')->updateOrInsert(
                    ['user_id' => $customer->id, 'product_id' => $simple->id],
                    ['rating' => 5, 'title' => 'Excellent quality', 'body' => 'A demo verified-purchase review for testing storefront ratings.', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]
                );
            }
            $variableVariant = $variable ? DB::table('product_variants as v')
                ->join('inventory_items as i', 'i.variant_id', '=', 'v.id')
                ->where('v.product_id', $variable->id)->where('v.status', 'active')
                ->whereRaw('i.stock_quantity > i.reserved_quantity')->select('v.*')->first() : null;
            if ($variable && $variableVariant) {
                DB::table('cart_items')->updateOrInsert(
                    ['cart_id' => $cartId, 'product_id' => $variable->id, 'variant_id' => $variableVariant->id],
                    ['quantity' => 1, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            if ($simple) {
                $orderId = DB::table('customer_orders')->where('number', 'BG-DEMO-1001')->value('id');
                if (!$orderId) {
                    $price = (float) ($simple->base_price ?? 0);
                    $orderId = DB::table('customer_orders')->insertGetId([
                        'number' => 'BG-DEMO-1001', 'user_id' => $customer->id, 'address_id' => $addressId,
                        'status' => 'shipped', 'payment_status' => 'pending', 'fulfillment_status' => 'shipped',
                        'payment_method' => 'cod', 'shipping_method_code' => 'standard', 'shipping_method_name' => 'Standard delivery',
                        'shipping_estimated_delivery' => '3–7 business days', 'currency' => 'USD',
                        'subtotal' => $price, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'total' => $price,
                        'shipping_address' => json_encode(['firstName' => 'Demo', 'lastName' => 'Customer', 'address1' => '123 Demo Market Road', 'address2' => 'Suite 4', 'city' => 'Lahore', 'state' => 'Punjab', 'postalCode' => '54000', 'country' => 'PK', 'phone' => '+923001234567']),
                        'created_at' => now()->subDays(2), 'updated_at' => now()->subDay(),
                    ]);
                    $productMetadata = is_array($simple->metadata) ? $simple->metadata : (json_decode($simple->metadata ?? '[]', true) ?: []);
                    DB::table('customer_order_items')->insert([
                        'order_id' => $orderId, 'product_id' => $simple->id, 'name' => $simple->name,
                        'product_slug' => $simple->slug, 'image_url' => data_get($productMetadata, 'images.0.url'),
                        'sku' => $simple->sku, 'quantity' => 1,
                        'unit_price' => $price, 'subtotal' => $price, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $shipmentId = DB::table('shipments')->where('order_id', $orderId)->value('id');
                if (!$shipmentId) {
                    $shipmentId = DB::table('shipments')->insertGetId([
                        'order_id' => $orderId, 'carrier' => 'Demo Logistics', 'tracking_number' => 'BG-DEMO-TRACK-1001',
                        'tracking_url' => 'https://example.com/track/BG-DEMO-TRACK-1001', 'status' => 'in_transit',
                        'estimated_delivery' => now()->addDays(3)->toDateString(), 'shipped_at' => now()->subDay(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('shipment_events')->insert([
                        'shipment_id' => $shipmentId, 'status' => 'in_transit', 'description' => 'Demo parcel is in transit.',
                        'location' => 'Lahore', 'occurred_at' => now()->subHours(8), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->command?->info('Demo scenario ready. Customer/admin/vendor password: Password123!');
    }
}
