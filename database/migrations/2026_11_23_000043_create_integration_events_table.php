<?php

// Goes in: database/migrations/ (new file). Additive only. Runs on MySQL and SQLite.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_events')) {
            return;
        }

        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('connection_id');
            $t->string('direction', 3);                        // in | out
            $t->string('topic', 64);
            $t->string('event_uuid', 36)->nullable()->unique(); // outbound: value of X-Skale-Event-Id
            $t->unsignedBigInteger('subject_id')->nullable();   // order id or product id the event is about
            $t->string('dedupe_key', 191)->unique();
            $t->longText('payload')->nullable();                // exact raw body; for outbound built on first attempt
            $t->string('status', 12)->default('received');      // received|processed|failed|dead|skipped
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamps();

            $t->index(['connection_id', 'direction', 'status']);
            $t->index(['direction', 'status', 'next_attempt_at']);
            $t->index(['connection_id', 'topic', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
