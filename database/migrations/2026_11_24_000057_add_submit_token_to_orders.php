<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Z1-3: the New Order form mints one submission token per form
     * mount and sends it with the create. The unique (business_id,
     * submit_token) index is the engine-level guarantee that a
     * double-click / browser retry / second tab can never book the
     * same submission twice - a duplicate insert fails on the index
     * and the caller is sent to the order the first submission made.
     * Nullable: every other intake path (and old form clients) simply
     * has no token, and NULLs never collide in a unique index.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('submit_token', 64)->nullable();
            $table->unique(['business_id', 'submit_token']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'submit_token']);
            $table->dropColumn('submit_token');
        });
    }
};
