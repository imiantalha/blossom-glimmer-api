<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Legacy duplicate of 2026_09_11_215700_add_catalog_constraints.
 *
 * Keep the migration file so databases that have recorded it remain compatible.
 * The preceding migration owns these schema changes; repeating them breaks
 * fresh installs with duplicate columns and constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty. See class documentation.
    }

    public function down(): void
    {
        // Intentionally empty. The preceding migration owns the schema.
    }
};
