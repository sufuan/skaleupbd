<?php

use App\Models\AiRule;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill the batch-15 additions to the Master Brain Rule Library
 * (AiRule::catalog) for every existing business: Abusive language,
 * Redelivery request, Payment methods question, Polite decline,
 * Courier did not call, Invoice or memo request, Gift wrap or note,
 * Reserve or hold request and Usage or ingredients question — the
 * library grows from 47 to 56 defs. AiRule::ensureDefaults is
 * firstOrCreate-keyed on (business_id, trigger_type, match_text), so
 * shops keep every existing row exactly as it is — seller edits and
 * deliberately deactivated rules included — and simply gain the
 * cases they are missing. (The match_text widening the library
 * needed already happened in migration 000062.)
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
