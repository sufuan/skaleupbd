<?php

// Goes in: database/migrations/2026_11_23_000043_extend_integration_connections_woo.php (new file).
// Additive: adds WooCommerce-specific tracking columns to integration_connections.
// Also adds a woo_status_map column for configurable status mapping (stored as JSON).
// Idempotent: wrapped in hasColumn() guards.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $t) {
            // WooCommerce-specific: the reconciliation cursor (last order/product modified_after timestamp).
            if (! Schema::hasColumn('integration_connections', 'woo_orders_cursor')) {
                $t->timestamp('woo_orders_cursor')->nullable()->after('last_error');
            }
            if (! Schema::hasColumn('integration_connections', 'woo_products_cursor')) {
                $t->timestamp('woo_products_cursor')->nullable()->after('woo_orders_cursor');
            }
            // JSON map of WooCommerce status => our status (or null to skip).
            // Default: {"processing":"Processing","on-hold":"Pending"}
            if (! Schema::hasColumn('integration_connections', 'woo_status_map')) {
                $t->text('woo_status_map')->nullable()->after('woo_products_cursor');
            }
            // Registered webhook IDs on the WooCommerce site (JSON array of ints).
            // Used to detect dead webhooks and re-register them in reconciliation.
            if (! Schema::hasColumn('integration_connections', 'woo_webhook_ids')) {
                $t->text('woo_webhook_ids')->nullable()->after('woo_status_map');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $t) {
            foreach (['woo_orders_cursor', 'woo_products_cursor', 'woo_status_map', 'woo_webhook_ids'] as $col) {
                if (Schema::hasColumn('integration_connections', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
