<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 000046 — add reconciliation cursor + timestamp columns to integration_connections.
 *
 * Place in: database/migrations/2026_11_23_000046_add_reconciliation_columns.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('integration_connections', 'woo_orders_cursor')) {
                // ISO timestamp of last order successfully reconciled.
                $table->string('woo_orders_cursor')->nullable()->after('woo_webhook_ids');
            }
            if (! Schema::hasColumn('integration_connections', 'woo_products_cursor')) {
                $table->string('woo_products_cursor')->nullable()->after('woo_orders_cursor');
            }
            if (! Schema::hasColumn('integration_connections', 'woo_reconciled_at')) {
                $table->timestamp('woo_reconciled_at')->nullable()->after('woo_products_cursor');
            }
            if (! Schema::hasColumn('integration_connections', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('woo_reconciled_at');
            }
            if (! Schema::hasColumn('integration_connections', 'last_event_at')) {
                $table->timestamp('last_event_at')->nullable()->after('last_synced_at');
            }
            if (! Schema::hasColumn('integration_connections', 'last_success_at')) {
                $table->timestamp('last_success_at')->nullable()->after('last_event_at');
            }
            if (! Schema::hasColumn('integration_connections', 'failure_count')) {
                $table->unsignedSmallInteger('failure_count')->default(0)->after('last_success_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $cols = [
                'woo_orders_cursor',
                'woo_products_cursor',
                'woo_reconciled_at',
                'last_synced_at',
                'last_event_at',
                'last_success_at',
                'failure_count',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('integration_connections', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
