<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->nullOnDelete();

            $table->foreign('feature_media_id')
                ->references('id')
                ->on('media')
                ->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('combination_key')->nullable()->after('sku');
            $table->unique(['product_id', 'combination_key']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['product_id', 'attribute_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'combination_key']);
            $table->dropColumn('combination_key');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['feature_media_id']);
        });
    }
};
