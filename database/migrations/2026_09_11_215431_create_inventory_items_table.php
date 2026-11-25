<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->noActionOnDelete();
            $table->boolean('manage_inventory')->default(true);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->timestamps();


        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX inventory_items_product_unique ON inventory_items (product_id) WHERE product_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX inventory_items_variant_unique ON inventory_items (variant_id) WHERE variant_id IS NOT NULL');
        } else {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->unique('product_id');
                $table->unique('variant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
