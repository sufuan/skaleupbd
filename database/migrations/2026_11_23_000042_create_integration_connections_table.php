<?php

// Goes in: database/migrations/ (new file). Additive only. Runs on MySQL and SQLite.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_connections')) {
            return;
        }

        Schema::create('integration_connections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id')->index();
            $t->string('platform', 16);                       // woo | custom
            $t->string('site_url', 500)->nullable();
            $t->string('webhook_url', 500)->nullable();       // custom sites: where we send signed events
            $t->string('status', 16)->default('pending');     // pending|active|degraded|failed|disabled
            $t->text('credentials_enc')->nullable();          // encrypted by the model cast
            $t->text('webhook_secret_enc')->nullable();       // encrypted by the model cast
            $t->string('webhook_secret_last4', 4)->nullable();// the only part of the secret the UI may see
            $t->string('public_token', 64)->nullable()->unique();
            $t->timestamp('last_event_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->unsignedInteger('failure_count')->default(0);
            $t->string('last_error', 500)->nullable();
            $t->timestamps();

            $t->unique(['business_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
