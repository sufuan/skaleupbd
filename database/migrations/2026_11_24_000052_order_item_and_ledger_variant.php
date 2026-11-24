<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Round 5 (K-H2): an order line must remember WHICH variant it was
        // for (not just the name string), and every stock ledger row a
        // variant movement writes must say so - the variant's stock pool
        // is separate from the parent product's, and the per-order held
        // accounting keys on it.
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id')->nullable()->index();
        });
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
