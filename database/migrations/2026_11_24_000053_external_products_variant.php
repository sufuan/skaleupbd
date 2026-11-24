<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 7 (Hunt R2/R4): an external_products mapping for a WooCommerce
 * variation must remember WHICH local variant it points at, so the stock
 * push sends the variant pool's figure and variation imports reserve the
 * variant's pool - not the parent product's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_products', 'variant_id')) {
            Schema::table('external_products', function (Blueprint $t) {
                $t->unsignedBigInteger('variant_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_products', 'variant_id')) {
            Schema::table('external_products', function (Blueprint $t) {
                $t->dropColumn('variant_id');
            });
        }
    }
};
