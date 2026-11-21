<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M19 purchase flow — seller payment claims. A claim is created when a
 * seller picks a pack (at registration, or later from Points & Billing):
 * the seller pays offline (bKash/Nagad), submits the payment details,
 * and a platform admin approves — approval runs TokenService::purchase
 * so the points land exactly like a manually recorded purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_payment_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('pack_id');
            $table->string('pack_name', 80);
            $table->unsignedInteger('pack_credits');
            $table->string('validity_type', 20)->default('monthly'); // monthly | yearly_wallet
            $table->decimal('amount_bdt', 12, 2); // pack price
            $table->decimal('onboarding_fee_bdt', 12, 2)->default(0); // first purchase only
            $table->decimal('total_bdt', 12, 2); // amount + fee — what the seller pays
            $table->string('method', 40)->nullable(); // bKash | Nagad
            $table->string('sender_number', 30)->nullable();
            $table->string('trx_id', 60)->nullable();
            $table->string('status', 20)->default('pending_payment')->index(); // pending_payment | pending_review | approved | rejected
            $table->string('admin_note', 300)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_payment_claims');
    }
};
