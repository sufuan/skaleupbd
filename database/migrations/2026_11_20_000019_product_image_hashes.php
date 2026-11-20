<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_hashes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->index();
            $table->foreignId('product_id')->index();
            $table->string('source')->default('gallery'); // gallery | customer | post
            $table->string('image_path');
            $table->string('hash', 16); // 64-bit perceptual hash, hex
            $table->timestamps();
            $table->unique(['business_id', 'image_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_hashes');
    }
};
