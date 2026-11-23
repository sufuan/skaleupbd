<?php

// Goes in: database/migrations/ (new file). Additive only. Used from stage 2 onward; created now so the
// numbering stays stable. external_variation_id is NOT NULL with '' for "no variation" on purpose:
// MySQL treats NULLs as distinct inside a unique index, which would allow duplicate rows.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('external_orders')) {
            Schema::create('external_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('connection_id');
                $t->string('external_order_id', 64);
                $t->unsignedBigInteger('order_id')->index();
                $t->string('last_pushed_status', 32)->nullable();
                $t->timestamp('last_pushed_at')->nullable();
                $t->timestamps();

                $t->unique(['connection_id', 'external_order_id']);
            });
        }

        if (! Schema::hasTable('external_products')) {
            Schema::create('external_products', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('connection_id');
                $t->string('external_id', 64);
                $t->string('external_variation_id', 64)->default('');
                $t->string('sku', 191)->nullable()->index();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('state', 12)->default('unmatched');   // matched | unmatched
                $t->string('hash', 64)->nullable();
                $t->timestamps();

                $t->unique(['connection_id', 'external_id', 'external_variation_id'], 'ext_products_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_products');
        Schema::dropIfExists('external_orders');
    }
};
