<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable();
            $table->enum('type', ['simple', 'variable']);
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');
            $table->unsignedBigInteger('feature_media_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE sku IS NOT NULL');
        } else {
            Schema::table('products', fn (Blueprint $table) => $table->unique('sku'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
