<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceLifecycleDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!config('storefront.seed_demo_catalog')) return;

        DB::transaction(function (): void {
            $customer = DB::table('users')->where('email', 'customer2@demo.blossomglimmer.local')->first();
            $admin = DB::table('users')->where('email', 'admin@demo.blossomglimmer.local')->first();
            $product = DB::table('products')->whereNotNull('vendor_id')->where('status', 'active')->where('type', 'simple')->orderBy('id')->first();
            if (!$customer || !$product) return;

            $vendor = DB::table('users')->where('id', $product->vendor_id)->first();
            $addressId = DB::table('customer_addresses')->where('user_id', $customer->id)->where('address1', '456 Demo Commerce Avenue')->value('id');
            if (!$addressId) {
                $addressId = DB::table('customer_addresses')->insertGetId([
                    'user_id' => $customer->id, 'first_name' => 'Demo', 'last_name' => 'Customer Two',
                    'address1' => '456 Demo Commerce Avenue', 'address2' => null, 'city' => 'Lahore',
                    'state' => 'Punjab', 'postal_code' => '54000', 'country' => 'PK',
                    'phone' => '+923001234568', 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $orderId = DB::table('customer_orders')->where('number', 'BG-DEMO-1002')->value('id');
            if (!$orderId) {
                $price = (float) ($product->base_price ?? 25);
                $metadata = is_array($product->metadata) ? $product->metadata : (json_decode($product->metadata ?? '[]', true) ?: []);
                $shippingAddress = [
                    'firstName' => 'Demo', 'lastName' => 'Customer Two', 'address1' => '456 Demo Commerce Avenue',
                    'address2' => null, 'city' => 'Lahore', 'state' => 'Punjab', 'postalCode' => '54000',
                    'country' => 'PK', 'phone' => '+923001234568',
                ];
                $orderId = DB::table('customer_orders')->insertGetId([
                    'number' => 'BG-DEMO-1002', 'user_id' => $customer->id, 'address_id' => $addressId,
                    'status' => 'delivered', 'payment_status' => 'paid', 'fulfillment_status' => 'delivered',
                    'payment_method' => 'mock_card', 'shipping_method_code' => 'standard',
                    'shipping_method_name' => 'Standard delivery', 'shipping_estimated_delivery' => '3–7 business days',
                    'currency' => 'USD', 'subtotal' => $price, 'discount' => 0, 'shipping' => 0, 'tax' => 0,
                    'total' => $price, 'shipping_address' => json_encode($shippingAddress),
                    'created_at' => now()->subDays(5), 'updated_at' => now()->subDay(),
                ]);
                $orderItemId = DB::table('customer_order_items')->insertGetId([
                    'order_id' => $orderId, 'product_id' => $product->id, 'name' => $product->name,
                    'product_slug' => $product->slug, 'image_url' => data_get($metadata, 'images.0.url'),
                    'sku' => $product->sku, 'quantity' => 1, 'unit_price' => $price, 'subtotal' => $price,
                    'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
                ]);
            } else {
                $orderItemId = DB::table('customer_order_items')->where('order_id', $orderId)->value('id');
                $price = (float) DB::table('customer_orders')->where('id', $orderId)->value('total');
            }

            DB::table('payment_transactions')->updateOrInsert(
                ['reference' => 'BG-DEMO-PAY-1002'],
                [
                    'order_id' => $orderId, 'user_id' => $customer->id, 'provider' => 'simulation',
                    'method' => 'mock_card', 'amount' => $price, 'currency' => 'USD', 'status' => 'succeeded',
                    'metadata' => json_encode(['simulation' => true, 'seeded' => true, 'notice' => 'No real payment was collected.']),
                    'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
                ]
            );

            if ($vendor && $orderItemId) {
                $fulfillmentId = DB::table('vendor_order_fulfillments')->where('order_id', $orderId)->where('vendor_id', $vendor->id)->value('id');
                if (!$fulfillmentId) {
                    $fulfillmentId = DB::table('vendor_order_fulfillments')->insertGetId([
                        'order_id' => $orderId, 'vendor_id' => $vendor->id, 'status' => 'delivered',
                        'carrier' => 'Demo Logistics', 'tracking_number' => 'BG-DEMO-TRACK-1002',
                        'tracking_url' => 'https://example.com/track/BG-DEMO-TRACK-1002',
                        'estimated_delivery' => now()->subDay()->toDateString(),
                        'shipped_at' => now()->subDays(3), 'delivered_at' => now()->subDay(),
                        'created_at' => now()->subDays(5), 'updated_at' => now()->subDay(),
                    ]);
                }
                DB::table('vendor_order_fulfillment_items')->updateOrInsert(
                    ['fulfillment_id' => $fulfillmentId, 'order_item_id' => $orderItemId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            DB::table('refund_requests')->updateOrInsert(
                ['order_id' => $orderId, 'user_id' => $customer->id],
                ['amount' => min(10, $price), 'reason' => 'Demo return request for refund workflow verification.', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]
            );

            DB::table('coupons')->updateOrInsert(
                ['code' => 'WELCOME10'],
                [
                    'name' => 'Demo welcome discount', 'discount_type' => 'percentage', 'discount_value' => 10,
                    'minimum_subtotal' => 0, 'maximum_discount' => 50, 'usage_limit' => 100,
                    'per_user_limit' => 1, 'usage_count' => 0, 'starts_at' => now()->subDay(),
                    'ends_at' => now()->addYear(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]
            );

            $ticketId = DB::table('support_tickets')->where('number', 'BG-SUP-DEMO-1002')->value('id');
            if (!$ticketId) {
                $ticketId = DB::table('support_tickets')->insertGetId([
                    'number' => 'BG-SUP-DEMO-1002', 'user_id' => $customer->id, 'order_id' => $orderId,
                    'subject' => 'Question about my delivered order', 'category' => 'order',
                    'priority' => 'normal', 'status' => 'open',
                    'message' => 'This is a seeded support ticket for testing the customer and admin support workflow.',
                    'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
                ]);
                DB::table('support_ticket_messages')->insert([
                    'ticket_id' => $ticketId, 'user_id' => $customer->id,
                    'message' => 'This is a seeded support ticket for testing the customer and admin support workflow.',
                    'internal_note' => false, 'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
                ]);
            }

            DB::table('marketplace_notifications')->updateOrInsert(
                ['user_id' => $customer->id, 'type' => 'demo.order.delivered', 'title' => 'Demo order delivered'],
                ['message' => 'Your demo order BG-DEMO-1002 has been delivered.', 'action_url' => '/account/orders/' . $orderId,
                    'data' => json_encode(['order_id' => $orderId]), 'read_at' => null, 'created_at' => now(), 'updated_at' => now()]
            );

            if ($vendor) {
                $hasSeededPayout = DB::table('vendor_payout_requests')
                    ->where('vendor_id', $vendor->id)
                    ->where('amount', 1)
                    ->where('status', 'pending')
                    ->where('payout_method', 'manual_simulation')
                    ->exists();
                if (!$hasSeededPayout) {
                    DB::table('vendor_payout_requests')->insert([
                        'vendor_id' => $vendor->id, 'amount' => 1, 'currency' => 'USD',
                        'status' => 'pending', 'payout_method' => 'manual_simulation',
                        'vendor_note' => 'Seeded payout request for admin approval testing.',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->command?->info('Marketplace lifecycle demo data seeded (paid/delivered order, refund, coupon, support ticket and payout request).');
    }
}
