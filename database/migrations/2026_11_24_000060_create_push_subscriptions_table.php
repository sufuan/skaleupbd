<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Browser Web Push subscriptions (owner order 2026-11-25): one row
     * per browser a signed-in user enabled notifications on. The
     * endpoint is the push service's own URL for that browser and is
     * unique across the platform; when a user re-subscribes from the
     * same browser the row is updated in place.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint')->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
