<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 000045 — add last_pushed_stock column to external_products.
 *
 * Place in: database/migrations/2026_11_23_000045_add_stock_push_columns.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_products', function (Blueprint $table) {
            if (! Schema::hasColumn('external_products', 'last_pushed_stock')) {
                // Last stock quantity we successfully pushed to WooCommerce.
                // NULL means we have never pushed (always push on first run).
                $table->integer('last_pushed_stock')->nullable()->after('external_variation_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_products', function (Blueprint $table) {
            if (Schema::hasColumn('external_products', 'last_pushed_stock')) {
                $table->dropColumn('last_pushed_stock');
            }
        });
    }
};
