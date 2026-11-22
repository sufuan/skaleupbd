<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration verification (owner order, 2026-11-22): when the
 * platform switch registration.verify_enabled is ON, a new seller must
 * enter a 6-digit code (SMS and/or email, Settings-chosen) before the
 * normal pending/claim flow opens. One row per user; only the hash of
 * the code is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash')->nullable();
            $table->string('channels', 20)->default('email'); // sms | email | both (snapshot of the send)
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_otps');
    }
};
