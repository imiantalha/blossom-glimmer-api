<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 40)->default('pending');
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 160)->nullable();
            $table->string('tracking_url', 1000)->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'vendor_id']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('vendor_order_fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained('vendor_order_fulfillments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('customer_order_items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['fulfillment_id', 'order_item_id']);
            $table->unique('order_item_id');
        });

        // Backfill existing marketplace orders into isolated vendor fulfilments.
        $items = DB::table('customer_order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNotNull('p.vendor_id')
            ->select('oi.id as order_item_id', 'oi.order_id', 'p.vendor_id')
            ->orderBy('oi.order_id')
            ->get()
            ->groupBy(fn ($item) => $item->order_id . ':' . $item->vendor_id);

        foreach ($items as $vendorItems) {
            $first = $vendorItems->first();
            $fulfillmentId = DB::table('vendor_order_fulfillments')->insertGetId([
                'order_id' => $first->order_id,
                'vendor_id' => $first->vendor_id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($vendorItems as $item) {
                DB::table('vendor_order_fulfillment_items')->insert([
                    'fulfillment_id' => $fulfillmentId,
                    'order_item_id' => $item->order_item_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_order_fulfillment_items');
        Schema::dropIfExists('vendor_order_fulfillments');
    }
};
