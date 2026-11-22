<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12 Sales Growth (বিক্রি বাড়ান) — Phase 1.
 *
 * A stage layer over data the app already holds (inbox conversations,
 * AI intents, orders): every conversation gets one stage row, every
 * stage change an append-only event, and the Marketing page reads
 * rollups + generated action tasks from those. Stage definitions are
 * versioned (metric_version) so a definition change backfills instead
 * of silently rewriting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('first_inbound_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('window_expires_at')->nullable();
            $table->boolean('contact_shared')->default(false);
        });

        Schema::create('conversation_stage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('conversation_id');
            // Highest stage ever reached (never decreases) and the stage
            // the conversation sits at now. Equal while nothing closes.
            $table->unsignedTinyInteger('stage_reached')->default(0);
            $table->unsignedTinyInteger('stage_current')->default(0);
            $table->timestamp('reached_at_1')->nullable();
            $table->timestamp('reached_at_2')->nullable();
            $table->timestamp('reached_at_3')->nullable();
            $table->timestamp('reached_at_4')->nullable();
            // Phase 1 closes only for: ordered | spam. (lost,
            // do_not_contact, handled_by_human arrive with their signals.)
            $table->string('closed_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('classifier_version')->nullable();
            $table->timestamps();
            $table->unique('conversation_id');
            $table->index(['business_id', 'stage_current']);
        });

        Schema::create('stage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedTinyInteger('from_stage')->nullable();
            $table->unsignedTinyInteger('to_stage');
            $table->string('reason_code'); // intent_price | order_intent | contact_shared | order_created | spam | angry | backfill | human_correction
            $table->json('evidence')->nullable(); // ids only: message_id, intent, order_id — never raw text
            $table->string('source')->default('rule'); // ai | rule | human | order | courier | system
            // Idempotency: one stage change per (conversation, key) —
            // replaying the same message/order id can never double-count.
            $table->string('dedupe_key')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'dedupe_key']);
        });

        Schema::create('insight_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->date('cohort_date');
            $table->string('channel');
            $table->unsignedInteger('wrote')->default(0);
            $table->unsignedInteger('interested')->default(0);
            $table->unsignedInteger('ready')->default(0);
            $table->unsignedInteger('ordered')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('complaints')->default(0);
            $table->unsignedInteger('metric_version')->default(1);
            $table->boolean('reconciled')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'cohort_date', 'channel']);
        });

        Schema::create('insight_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('kind'); // complaint | ready | interested | channel_gap | leak | ad
            $table->string('dedupe_key');
            $table->date('task_date');
            $table->json('title_params')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->decimal('est_value', 12, 2)->nullable();
            $table->unsignedTinyInteger('priority')->default(5);
            $table->string('status')->default('open'); // open | done | snoozed | expired
            $table->unsignedBigInteger('assignee')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'dedupe_key', 'task_date']);
        });

        Schema::create('insight_task_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('conversation_id');
            $table->timestamps();
            $table->unique(['task_id', 'conversation_id']);
        });

        Schema::create('insight_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('type'); // draft_created | message_sent | call_logged | offer_sent
            $table->string('channel')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_actions');
        Schema::dropIfExists('insight_task_members');
        Schema::dropIfExists('insight_tasks');
        Schema::dropIfExists('insight_daily');
        Schema::dropIfExists('stage_events');
        Schema::dropIfExists('conversation_stage');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['first_inbound_at', 'last_inbound_at', 'last_outbound_at', 'window_expires_at', 'contact_shared']);
        });
    }
};
