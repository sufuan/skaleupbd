<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider/delivery tracking for sms_logs: which gateway sent the SMS, the
 * provider's own message id (the DLR webhook matches on it), the delivery
 * state reported back, the raw provider payload, and the failure reason.
 * updated_at is added because the table shipped with created_at only and a
 * delivery update needs its own timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->text('provider_response')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['provider', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_message_id']);
            $table->dropColumn(['provider', 'provider_message_id', 'delivery_status', 'provider_response', 'error', 'updated_at']);
        });
    }
};
