<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('external_id')->nullable()->index(); // Meta comment id
            $table->string('post_ref')->nullable();             // Meta post id
            $table->string('author_ref')->nullable();           // commenter id (PSID / IG id)
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'post_ref', 'author_ref']);
        });
    }
};
