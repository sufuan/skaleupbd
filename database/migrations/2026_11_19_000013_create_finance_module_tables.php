<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('cash'); // cash | bank | mobile
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['business_id', 'active']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('expense'); // income | expense
            $table->boolean('is_system')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['business_id', 'kind']);
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->string('direction'); // in | out
            $table->string('type'); // income expense transfer owner_in owner_out advance courier_payout
            $table->decimal('amount', 14, 2);
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('source')->default('manual'); // manual | order | courier | system
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->unsignedBigInteger('transfer_pair_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('reversal_of')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->date('txn_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'txn_date']);
            $table->index(['business_id', 'account_id', 'txn_date']);
        });

        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('expected', 14, 2)->default(0);
            $table->decimal('counted', 14, 2)->default(0);
            $table->decimal('difference', 14, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'account_id', 'date']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('finance_access')->default('full'); // full | entry
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('finance_access');
        });
        Schema::dropIfExists('daily_closings');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
