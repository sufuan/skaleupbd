<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // product_variants itself already exists (inventory module, 000008) —
        // orders only need to remember WHICH variant a line was for.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('variant_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('variant_name');
        });
    }
};
