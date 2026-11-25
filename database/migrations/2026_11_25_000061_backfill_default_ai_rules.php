<?php

use App\Models\AiRule;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill the Rule Brain's default intent rules for every existing
 * business. Until now the defaults were installed only by
 * MessagesSeeder for the demo shop, so every real shop had zero
 * ai_rules rows and its AI could only answer with the generic price
 * fallback — no bargaining, no order intake. AiRule::ensureDefaults
 * is firstOrCreate-keyed on (business_id, trigger_type, match_text),
 * so shops that already have rules keep them exactly as they are,
 * including seller edits and deliberately deactivated rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Business::pluck('id') as $bizId) {
            AiRule::ensureDefaults((int) $bizId);
        }
    }

    public function down(): void
    {
        // Backfilled default rules are indistinguishable from rules a
        // shop has since edited or come to rely on; no safe automatic undo.
    }
};
