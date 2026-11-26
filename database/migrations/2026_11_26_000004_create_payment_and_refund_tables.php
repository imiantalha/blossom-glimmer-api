<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('simulation');
            $table->string('method', 40);
            $table->string('reference', 100)->unique();
            $table->string('idempotency_key', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 1000);
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->string('reference', 100)->nullable()->unique();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('payment_transactions');
    }
};
