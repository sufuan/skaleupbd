<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar badge "seen" watermarks: a badge count for a sub-menu means
 * "items that arrived since THIS user last opened that sub-menu page".
 * One row per (user, key); seen_at is the moment they last visited.
 * Keys: orders_list, delivery_ready, purchases_toreceive, work_control,
 * sellers_pending. Messages is deliberately NOT watermarked — its badge
 * is the real unread count and clears as conversations are read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sidebar_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('business_id')->nullable(); // snapshot of the user's shop (null = platform)
            $table->string('key', 40);
            $table->timestamp('seen_at');
            // Highest row id in the key's table when the watermark was
            // written — breaks same-second ties (timestamps are stored
            // at second precision, so a row created in the same second
            // as the visit still has a higher id and counts as new).
            $table->unsignedBigInteger('seen_id')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sidebar_seen');
    }
};
