<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('courier_type'); // PATHAO STEADFAST REDX CARRYBEE PAPERFLY ECOURIER BAHOK OTHERS
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_preferred')->default(false);
            $table->text('credentials')->nullable(); // encrypted JSON (model cast)
            $table->decimal('charge_inside_dhaka', 12, 2)->default(0);
            $table->decimal('charge_outside_dhaka', 12, 2)->default(0);
            $table->text('default_shipping_note')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_pickup_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_method_id')->constrained()->cascadeOnDelete();
            $table->string('store_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('courier_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(10);
            $table->string('scope'); // product | category | district | area
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable();
            $table->string('district')->nullable();
            $table->string('area')->nullable();
            $table->foreignId('courier_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pickup_store_id')->nullable()->constrained('courier_pickup_stores')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('courier_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_store_id')->nullable()->constrained('courier_pickup_stores')->nullOnDelete();
            $table->string('courier_status')->nullable();
            $table->string('tracking_id')->nullable();
            $table->string('consignment_id')->nullable();
            $table->string('booking_status')->nullable(); // queued booked failed retrying fallback cancelled
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
        });

        Schema::create('courier_booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_store_id')->nullable()->constrained('courier_pickup_stores')->nullOnDelete();
            $table->string('mode')->default('manual_override'); // suggested manual_override manual_cn
            $table->string('booking_status'); // pending booked failed retrying fallback cancelled
            $table->string('tracking_id')->nullable();
            $table->string('consignment_id')->nullable();
            $table->string('label_url')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(1);
            $table->string('created_by')->default('user');
            $table->timestamps();
        });

        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_method_id')->constrained()->cascadeOnDelete();
            $table->string('payment_id')->nullable(); // the courier's own payout id, e.g. Steadfast SFC-88213
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('method')->nullable();
            $table->decimal('due_bills', 12, 2)->nullable();
            $table->decimal('paid_bills', 12, 2)->nullable();
            $table->decimal('charges', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('status_label')->nullable();
            $table->string('reference')->nullable();
            $table->string('source')->default('upload'); // api upload payment_entry
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('courier_settlements')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0); // net amount this payout gave for the parcel
            $table->boolean('disputed')->default(false);
            $table->timestamps();
        });

        Schema::create('courier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_method_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('date');
            $table->string('reference')->nullable();
            $table->string('deposit_account')->nullable();
            $table->string('status')->default('unmatched'); // unmatched allocated difference
            $table->foreignId('settlement_id')->nullable()->constrained('courier_settlements')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scan_type'); // shipping return rts
            $table->string('result'); // success failed duplicate
            $table->string('code')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('work_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // booking_failure settlement_difference parcel_issue
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_events');
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('courier_payments');
        Schema::dropIfExists('courier_settlement_allocations');
        Schema::dropIfExists('courier_settlements');
        Schema::dropIfExists('courier_booking_logs');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_method_id');
            $table->dropConstrainedForeignId('pickup_store_id');
            $table->dropColumn(['courier_status', 'tracking_id', 'consignment_id', 'booking_status', 'shipped_at', 'delivered_at', 'returned_at']);
        });
        Schema::dropIfExists('courier_assignment_rules');
        Schema::dropIfExists('courier_pickup_stores');
        Schema::dropIfExists('courier_methods');
    }
};
