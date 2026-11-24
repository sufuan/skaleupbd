<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Z1-4: the external API's dedup was a bare check-then-insert with
     * nothing behind it - two concurrent POSTs carrying the same
     * externalRef (or the same x-idempotency-key) could both pass the
     * check and both insert on InnoDB. These unique indexes are the
     * engine-level guarantee: the second insert fails on the index,
     * and the create path answers with the original order instead.
     * NULL refs/keys never collide, so intakes that carry neither are
     * unaffected.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'idempotency_key']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->unique(['business_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'external_ref']);
            $table->dropUnique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'idempotency_key']);
        });
    }
};
