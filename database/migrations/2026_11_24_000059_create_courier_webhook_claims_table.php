<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Z1-9: courier webhook deliveries are claimed BEFORE they are
     * applied - one row per delivery, inserted with insertOrIgnore
     * against this unique key. A duplicate delivery (courier retry,
     * double fire) finds its claim already present and is answered
     * without re-applying the status or re-sending any SMS. This
     * replaces the old check-then-put cache idempotency, which two
     * simultaneous deliveries could both pass.
     */
    public function up(): void
    {
        Schema::create('courier_webhook_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_key', 191)->unique();
            $table->string('source', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_webhook_claims');
    }
};
