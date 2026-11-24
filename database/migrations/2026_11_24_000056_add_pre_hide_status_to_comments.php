<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 9 (Hunt Y2-15): a comment's single status column carried two
 * facts at once - workflow state and platform visibility. Hiding a
 * comment overwrote its status, so unhiding restored 'awaiting' even
 * when a private reply had already been sent, erasing that fact from
 * the record. The status a comment had just before it was hidden is
 * now kept in pre_hide_status and restored on unhide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comments', 'pre_hide_status')) {
            Schema::table('comments', function (Blueprint $t) {
                $t->string('pre_hide_status')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('comments', 'pre_hide_status')) {
            Schema::table('comments', function (Blueprint $t) {
                $t->dropColumn('pre_hide_status');
            });
        }
    }
};
