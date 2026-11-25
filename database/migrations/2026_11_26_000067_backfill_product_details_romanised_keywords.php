<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Romanised attribute words for CUSTOMISED Product details rows
 * (brain fix batch 17, live C1): migration 000065 widened only
 * rows still holding the untouched catalog default — a seller who
 * had edited the rule's keyword list kept their list, and with it
 * the gap: "ojon koto?" matched nothing on their shop and the
 * price rule answered. This migration APPENDS the romanised words
 * to any Product-details keyword row that does not already carry
 * them. Append only: the seller's own keywords stay byte-identical
 * at the front, their reply template and action are never touched,
 * and a row already carrying the words (untouched default, the
 * current catalog, or a previous run of this migration) is left
 * exactly as it is.
 */
return new class extends Migration
{
    private const WORDS = 'ojon koto, ojon, wajan koto, wajan, size koto, map koto';

    public function up(): void
    {
        $rows = DB::table('ai_rules')
            ->where('trigger_type', 'keyword')
            ->where('name', 'Product details question')
            ->get(['id', 'match_text']);
        foreach ($rows as $row) {
            $match = (string) $row->match_text;
            if (str_contains($match, 'ojon')) {
                continue;
            }
            $base = rtrim($match, " \t,");
            DB::table('ai_rules')
                ->where('id', $row->id)
                ->update(['match_text' => ($base === '' ? self::WORDS : $base . ', ' . self::WORDS), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Not reversed: which rows were appended to is not recorded,
        // and the wider keyword list is safe to keep.
    }
};
