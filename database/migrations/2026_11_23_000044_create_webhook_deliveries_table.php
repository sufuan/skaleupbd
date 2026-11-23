<?php

// Goes in: database/migrations/ (new file). Additive only. Runs on MySQL and SQLite.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_deliveries')) {
            return;
        }

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('connection_id');
            $t->unsignedBigInteger('event_id');
            $t->string('topic', 64);
            $t->unsignedSmallInteger('attempt');
            $t->string('outcome', 12);                          // delivered | failed | blocked
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('error', 300)->nullable();
            $t->string('response_excerpt', 300)->nullable();
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['connection_id', 'id']);
            $t->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
