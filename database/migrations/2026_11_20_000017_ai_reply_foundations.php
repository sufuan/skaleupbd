<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('external_id')->nullable()->index();
            $table->string('attachment_type')->nullable();
            $table->string('attachment_path')->nullable();
            $table->json('attachment_meta')->nullable();
            // One Meta message id is processed once per business — a
            // redelivered webhook can never create a second reply.
            $table->unique(['business_id', 'external_id'], 'chat_messages_biz_external_unique');
        });

        Schema::table('ai_activity', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->json('meta')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->date('offer_starts_at')->nullable();
            $table->date('offer_ends_at')->nullable();
            $table->text('aliases')->nullable();
            $table->unsignedTinyInteger('max_discount_pct')->nullable();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('focus_at')->nullable();
            $table->string('state')->nullable();
            $table->json('state_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['focus_at', 'state', 'state_data']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['offer_price', 'offer_starts_at', 'offer_ends_at', 'aliases', 'max_discount_pct']);
        });
        Schema::table('ai_activity', function (Blueprint $table) {
            $table->dropColumn(['source', 'latency_ms', 'tokens', 'meta']);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('chat_messages_biz_external_unique');
            $table->dropColumn(['external_id', 'attachment_type', 'attachment_path', 'attachment_meta']);
        });
    }
};
