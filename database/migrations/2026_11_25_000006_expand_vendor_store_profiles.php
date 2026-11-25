<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('commission_type', 30)->default('percentage');
            $table->decimal('commission_value', 10, 2)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['status', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->dropIndex(['status', 'deleted_at']);
            $table->dropColumn([
                'name', 'banner_url', 'email', 'phone', 'status',
                'commission_type', 'commission_value', 'approved_at', 'deleted_at',
            ]);
        });
    }
};
