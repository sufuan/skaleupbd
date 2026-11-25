<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Romanised attribute words (brain fix batch 16, live C1): the
 * catalog's Product details question rule learned the romanised
 * forms customers actually type — "ojon koto?" (ওজন), "wajan",
 * "size koto", "map koto" — after "CRUD-101 er ojon koto?" was
 * answered with the product's price. ensureDefaults never modifies
 * an existing row, so shops installed before the change still
 * carry the old keyword list. Update ONLY rows that still hold
 * the untouched default (same name AND same match_text the old
 * catalog shipped): a seller who edited the rule keeps their edit
 * (the migration-000063 pattern).
 */
return new class extends Migration
{
    private const OLD_MATCH = 'কী দিয়ে তৈরি, কি কাপড়, কাপড়ের মান, ম্যাটেরিয়াল, ওজন কত, সাইজ কত ইঞ্চি, মাপ কত, ফিচার, স্পেসিফিকেশন, বিস্তারিত বলুন, ডিটেইল, details dao, material ki, kapor ki, fabric, weight, spec, specification, features, details';

    private const NEW_MATCH = 'কী দিয়ে তৈরি, কি কাপড়, কাপড়ের মান, ম্যাটেরিয়াল, ওজন কত, সাইজ কত ইঞ্চি, মাপ কত, ফিচার, স্পেসিফিকেশন, বিস্তারিত বলুন, ডিটেইল, details dao, material ki, kapor ki, fabric, weight, spec, specification, features, details, ojon koto, ojon, wajan koto, wajan, size koto, map koto';

    public function up(): void
    {
        DB::table('ai_rules')
            ->where('trigger_type', 'keyword')
            ->where('name', 'Product details question')
            ->where('match_text', self::OLD_MATCH)
            ->update(['match_text' => self::NEW_MATCH, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversed: which rows were widened is not recorded, and
        // the wider keyword list is safe to keep.
    }
};
