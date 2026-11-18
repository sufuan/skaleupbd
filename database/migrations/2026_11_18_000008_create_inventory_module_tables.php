<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- products: fields the Add Product / Edit forms carry ----
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('image')->nullable();
            $table->json('gallery')->nullable();
            $table->string('type')->default('single'); // single | combo | wrap
            $table->boolean('manage_stock')->default(true);
            $table->integer('alert_qty')->default(5);
            $table->boolean('featured')->default(false);
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit', 8)->default('kg');
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->text('shipping_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('warranty_policy')->nullable();
            $table->string('warranty_coverage')->nullable();
            $table->decimal('original_price', 12, 2)->nullable();
        });

        // ---- stock_ledger: the ledger is the source of truth — every row
        // carries the balance before/after plus cost impact and who did it ----
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->integer('prev_stock')->nullable();
            $table->integer('new_stock')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('value_impact', 12, 2)->nullable();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('done');
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('product_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('name'); // e.g. "Size: L / Color: Red"
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('stock_qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->timestamps();
        });

        Schema::create('combo_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('combo_product_id')->index();
            $table->unsignedBigInteger('component_product_id')->index();
            $table->integer('qty')->default(1);
            $table->timestamps();
        });

        Schema::create('shared_inventory_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('shared_inventory_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->timestamps();
        });

        Schema::create('stock_link_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('stock_link_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->string('type')->default('store'); // store | warehouse | central
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->integer('qty')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('from_branch_id');
            $table->unsignedBigInteger('to_branch_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('qty');
            $table->string('status')->default('pending'); // pending | sent | received | cancelled
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('claim_no');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->text('issue');
            $table->string('type')->nullable();
            // received | in_repair | sent_to_supplier | back_from_supplier | ready_for_pickup | closed | rejected | cancelled
            $table->string('status')->default('received');
            $table->string('resolution')->nullable();
            $table->string('reject_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->date('received_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('closed_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('branch_stock');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('stock_link_members');
        Schema::dropIfExists('stock_link_groups');
        Schema::dropIfExists('shared_inventory_members');
        Schema::dropIfExists('shared_inventory_groups');
        Schema::dropIfExists('combo_components');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_brands');
        Schema::dropIfExists('product_categories');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn(['prev_stock', 'new_stock', 'unit_cost', 'value_impact', 'reference', 'branch_id', 'user_id', 'reason', 'status']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'brand_id', 'image', 'gallery', 'type', 'manage_stock', 'alert_qty', 'featured', 'weight', 'weight_unit', 'short_description', 'long_description', 'shipping_note', 'admin_note', 'warranty_policy', 'warranty_coverage', 'original_price']);
        });
    }
};
