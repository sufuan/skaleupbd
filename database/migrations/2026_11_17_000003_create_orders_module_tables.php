<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('business_id');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->string('name')->nullable();
            $table->string('name_bangla')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('district')->nullable();
            $table->string('area')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'phone']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->integer('stock_qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'sku']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // customer snapshot
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('district')->nullable();
            $table->string('area')->nullable();
            $table->string('source')->default('DIRECT');
            $table->string('site')->nullable();
            $table->string('status')->default('Pending');
            $table->string('processing_status')->nullable();
            $table->string('delivery_method')->nullable();
            $table->text('delivery_note')->nullable();
            $table->text('shipping_note')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('advance', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('payment_method')->default('COD');
            $table->string('cancellation_reason')->nullable();
            $table->boolean('is_preorder')->default(false);
            $table->string('preorder_reason')->nullable();
            $table->date('notify_date')->nullable();
            $table->boolean('preorder_notified')->default(false);
            $table->string('external_ref')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('ip')->nullable();
            $table->string('device')->nullable();
            $table->string('fraud_verdict')->default('not_checked'); // safe | risky | not_checked
            $table->integer('fraud_rate')->nullable();
            $table->string('fraud_note')->nullable();
            $table->string('auto_call_status')->default('off');
            $table->string('taken_by')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'invoice_no']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_phone']);
            $table->index(['business_id', 'idempotency_key']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('action'); // action | sms | note | system | super_edit
            $table->text('text');
            $table->json('meta')->nullable();
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->boolean('is_visible')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('ip_phone_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // ip | phone | device
            $table->string('value');
            $table->string('reason')->nullable();
            $table->string('added_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'type', 'value']);
        });

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // reserve | release_reserve | ship_out | return_in | adjust
            $table->integer('qty');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // reminder | advance
            $table->string('name');
            $table->text('body');
            $table->timestamps();
            $table->unique(['business_id', 'key']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash');
            $table->string('last4')->nullable();
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('staff_name')->nullable();
            $table->string('outcome'); // verified | no_answer | phone_off | wrong_number | failed
            $table->integer('minutes')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('order_ref'); // invoice number of the deleted order
            $table->string('deleted_by')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('ip_phone_blocks');
        Schema::dropIfExists('order_sources');
        Schema::dropIfExists('order_activities');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn('is_admin');
        });
        Schema::dropIfExists('businesses');
    }
};
