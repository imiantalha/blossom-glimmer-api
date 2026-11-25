<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('type', 32);
            $table->string('status', 24)->default('pending');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->json('details')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_requests');
    }
};
