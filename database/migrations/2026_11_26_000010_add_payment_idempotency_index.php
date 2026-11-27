<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX payment_transactions_user_idempotency_unique ON payment_transactions (user_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        } else {
            Schema::table('payment_transactions', function ($table) {
                $table->unique(['user_id', 'idempotency_key'], 'payment_transactions_user_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function ($table) {
            $table->dropUnique('payment_transactions_user_idempotency_unique');
        });
    }
};
