<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('description');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('description');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->text('external_url')->nullable()->after('file_path');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->string('eyebrow')->nullable()->after('name');
            $table->json('featured_stores')->nullable()->after('category_slugs');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->unsignedInteger('sold_count')->default(0);
        });

        Schema::create('vendor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_profiles');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'is_new_arrival', 'sold_count']);
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['eyebrow', 'featured_stores']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('external_url');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
