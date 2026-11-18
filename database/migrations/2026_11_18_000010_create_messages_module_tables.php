<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('channel'); // facebook | whatsapp | instagram
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('status')->default('open');
            $table->boolean('ai_paused')->default(false);
            $table->boolean('needs_human')->default(false);
            $table->decimal('neg_offer', 12, 2)->nullable();
            $table->unsignedInteger('neg_step')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('direction'); // in | out
            $table->string('sender'); // customer | staff | ai
            $table->text('body');
            $table->unsignedBigInteger('ai_rule_id')->nullable();
            $table->string('status')->default('sent'); // sent | draft | flagged | rejected
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('channel'); // facebook | instagram
            $table->string('post_label');
            $table->string('author_name');
            $table->text('body');
            $table->string('status')->default('awaiting'); // awaiting | replied | private_sent | hidden | done
            $table->text('private_reply_body')->nullable();
            $table->timestamp('private_reply_at')->nullable();
            $table->boolean('hidden_by_ai')->default(false);
            $table->boolean('liked')->default(false);
            $table->unsignedBigInteger('ai_rule_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });

        Schema::create('comment_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('comment_id')->index();
            $table->string('kind')->default('public'); // public | private
            $table->string('sender')->default('staff'); // staff | ai
            $table->text('body');
            $table->string('status')->default('sent'); // sent | draft | flagged
            $table->unsignedBigInteger('ai_rule_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->string('trigger_type')->default('intent'); // intent | keyword
            $table->string('match_text')->nullable();
            $table->string('action')->default('answer'); // answer | draft | hide | handover
            $table->text('reply_template')->nullable();
            $table->string('channel')->nullable(); // null = all
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_rule_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->json('rule_data')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->timestamps();
        });

        Schema::create('ai_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('action'); // auto_sent | draft_created | edited_sent | flagged | comment_hidden | handed_human | proposal_created | rule_fired
            $table->text('detail')->nullable();
            $table->unsignedBigInteger('ai_rule_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
        Schema::dropIfExists('ai_activity');
        Schema::dropIfExists('ai_rule_proposals');
        Schema::dropIfExists('ai_rules');
        Schema::dropIfExists('comment_replies');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversations');
    }
};
