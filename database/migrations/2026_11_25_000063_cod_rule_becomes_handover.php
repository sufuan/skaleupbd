<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COD truthfulness (brain fix batch 2): the Master Library's Cash on
 * delivery rule changed from 'draft' to 'handover' in AiRule::catalog()
 * — its acknowledgement promises the shop will check its policy and
 * confirm, and only a handover actually flags a human to do that.
 * ensureDefaults never modifies an existing row, so shops installed
 * before the change still carry the old action. Update ONLY rows that
 * still hold the untouched default (same action AND same template the
 * old catalog shipped): a seller who edited the rule keeps their edit.
 */
return new class extends Migration
{
    private const OLD_TEMPLATE = '{honorific}, পেমেন্ট পদ্ধতি নিয়ে আপনার প্রশ্নটি দোকানের নিয়ম দেখে নিশ্চিত করে জানাচ্ছি।';

    public function up(): void
    {
        DB::table('ai_rules')
            ->where('trigger_type', 'intent')
            ->where('match_text', 'cod')
            ->where('action', 'draft')
            ->where('reply_template', self::OLD_TEMPLATE)
            ->update(['action' => 'handover', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversed: which rows were flipped is not recorded, and a
        // handover COD rule is safe to keep.
    }
};
