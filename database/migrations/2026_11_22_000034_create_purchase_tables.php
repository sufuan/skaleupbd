<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 Purchase — suppliers, purchase bills, their lines, the payments
 * allocated against them, and bill attachments.
 *
 * Money rules (brief): creating a purchase moves NO cash (nothing posts
 * to Finance); a purchase's due is DERIVED = total − allocated payments.
 * Receiving posts stock only, through StockService into stock_ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->unsignedInteger('terms_days')->default(30);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->date('date');
            $table->text('note')->nullable();
            $table->string('tags')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('kind', 10)->default('bill'); // bill | quick
            $table->timestamps();
            $table->index(['business_id', 'date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_received')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();
        });

        // Allocation rows: each row ties one amount of a payment to one
        // purchase. The payment header (account, note) lives on the single
        // Finance entry the payment posts (finance_entry_id, same for all
        // rows of one payment action).
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->unsignedBigInteger('finance_entry_id')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_attachments');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
