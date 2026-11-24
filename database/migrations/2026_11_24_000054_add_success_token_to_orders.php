<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 8 (Hunt U3): order success pages were enumerable - invoice
 * numbers are sequential per shop and /order-success/{invoice} needed
 * no credential, so anyone could walk the numbers and read every
 * order's total. Each order now carries an unguessable success token,
 * minted at creation in OrderService; the public success page requires
 * the matching token and 404s without it. Orders created before this
 * migration have no token and their bare invoice links stop working,
 * which is the point - the token URL is handed out at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'success_token')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('success_token', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'success_token')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropColumn('success_token');
            });
        }
    }
};
