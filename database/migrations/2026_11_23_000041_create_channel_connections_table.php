<?php

use App\Services\ChannelConnectionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ধাপ ৫: per-seller channel connections. Until now the whole platform
 * had ONE Meta connection in the global settings keys (channels.*) and
 * every inbound event was routed to the single shop named by
 * channels.business_id. From here each shop owns rows in
 * channel_connections (its own Facebook Page / WhatsApp number, its own
 * token, encrypted), webhook intake routes by the event's own Page ID /
 * phone_number_id, and sends resolve the conversation's connection.
 *
 * The data step moves the existing global connection into rows owned
 * by the shop channels.business_id names (falling back to the first
 * business, exactly as the old routing did), and backfills the
 * connection stamp on that shop's existing conversations/comments.
 * The global keys are NOT deleted: the send path keeps them as a
 * transition fallback for rows that predate this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('channel', 20); // facebook | whatsapp | instagram
            // THE routing key: Page ID / Phone Number ID / IG user ID.
            $table->string('external_id', 60);
            // WABA ID for WhatsApp (a WABA can hold several numbers).
            $table->string('parent_external_id', 60)->nullable();
            $table->string('display_name')->nullable();
            $table->text('token_enc')->nullable();
            $table->string('token_type', 20)->default('manual'); // page | user | system_user | manual
            $table->string('connect_method', 20)->default('manual'); // manual | oauth
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('connected'); // connected | error | disconnected
            $table->string('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            // One Page/number belongs to exactly ONE shop platform-wide:
            // routing stays a single unambiguous lookup, and no second
            // shop can claim a Page that is already connected.
            $table->unique(['channel', 'external_id']);
            $table->index(['business_id', 'channel']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('channel_connection_id')->nullable()->constrained('channel_connections')->nullOnDelete();
        });
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('channel_connection_id')->nullable()->constrained('channel_connections')->nullOnDelete();
        });

        // Move the platform's existing global connection into rows.
        app(ChannelConnectionService::class)->migrateLegacyGlobal();
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_connection_id');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_connection_id');
        });
        Schema::dropIfExists('channel_connections');
    }
};
