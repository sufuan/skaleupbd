<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('audience_key'); // all | bought_30d | dormant_60d | unconfirmed_today | outside_dhaka
            $table->string('audience_label');
            $table->integer('recipients')->default(0);
            $table->text('message');
            $table->string('coupon')->nullable();
            $table->integer('parts')->default(1);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('status')->default('sent'); // sending | sent
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type'); // campaign | automation
            $table->string('source_key'); // campaign id (as string) | flow key
            $table->string('source_label');
            $table->string('ref')->nullable(); // invoice no / order ref for per-order dedupe
            $table->text('body');
            $table->integer('parts')->default(1);
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('status')->default('sent'); // sent | skipped_cap | skipped_dnd | failed
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'phone', 'created_at']);
            $table->index(['business_id', 'source_type', 'source_key']);
        });

        Schema::create('sms_do_not_send', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->timestamps();
            $table->unique(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_do_not_send');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_campaigns');
    }
};
