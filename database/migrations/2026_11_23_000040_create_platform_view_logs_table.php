<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ধাপ ৩: the audit trail for view-as-seller (PlatformViewLog). One row
 * per enter, manual exit, expiry and blocked write attempt. Its own
 * table (not settings_changes, not role_change_logs): those answer
 * "what setting/role changed" inside a shop; this one answers "which
 * platform admin was inside which shop, and what did the read-only
 * guard stop". The future platform Audit Log page reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_view_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('action', 30); // enter | exit | expired | write_blocked
            $table->string('route')->nullable(); // for write_blocked: "POST /orders/1/approve"
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('detail')->nullable();
            // Log-table timestamp pattern (DeletionLog): created_at
            // only, stamped in PHP by the model's StampsCreatedAt
            // trait; the database default is only a backstop.
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_view_logs');
    }
};
