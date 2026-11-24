<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 9 (Hunt Y4 F10): seller approve/reject decisions left no audit
 * trail - approving stamped only the business row, and rejecting
 * recorded no actor and no time anywhere (the note was the only trace,
 * and a later re-approval erased even that). Every platform decision
 * on a seller now lands in this append-only log: who decided, what
 * they decided, when, and the note they gave. Rows are never updated,
 * so a rejection stays on record after a later approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_decision_logs')) {
            Schema::create('seller_decision_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('action', 20); // approved | rejected
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_decision_logs');
    }
};
