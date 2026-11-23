<?php

// Goes in: database/migrations/2026_11_23_000044_add_woo_push_map_column.php (new file).
// Additive. Adds woo_push_map (JSON text) to integration_connections.
// Keeps woo_status_map (the inbound map) untouched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('integration_connections', 'woo_push_map')) {
                $t->text('woo_push_map')->nullable()->after('woo_webhook_ids')
                    ->comment('JSON map of our status => WooCommerce status for outbound write-back. null value = do not push.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $t) {
            if (Schema::hasColumn('integration_connections', 'woo_push_map')) {
                $t->dropColumn('woo_push_map');
            }
        });
    }
};
