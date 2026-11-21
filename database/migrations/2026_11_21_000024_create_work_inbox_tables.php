<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key')->nullable(); // null = manual task
            $table->string('source')->default('manual'); // orders|delivery|messages|inventory|purchase|followup|finance|system|manual
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('priority')->default('normal'); // normal|urgent|critical
            $table->string('status')->default('open'); // open|in_progress|done|auto_closed
            $table->string('assignee_mode')->default('pool'); // pool|role|user|processor|chat|admin
            $table->string('assignee_role')->nullable(); // delivery|accounts|purchase|claim|admin
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type')->nullable(); // order|product|conversation|comment|claim|work_event|block|closing|chat_message|phone
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_label')->nullable();
            $table->string('link')->nullable();
            $table->string('fingerprint')->nullable(); // one open task per business+rule+fingerprint
            $table->timestamp('due_at')->nullable();
            $table->text('done_note')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->string('auto_close_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'rule_key', 'fingerprint', 'status'], 'work_tasks_dedupe_idx');
            $table->index(['business_id', 'assignee_user_id', 'status'], 'work_tasks_assignee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
    }
};
