<?php

use App\Models\AiRule;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the expanded Master Brain Rule Library (AiRule::catalog)
 * for every existing business. The library grew from the original 9
 * intent rules to the full case library (handover cases, fine-grained
 * intents, keyword rules); shops created earlier only hold the rows
 * they were originally provisioned with. AiRule::ensureDefaults is
 * firstOrCreate-keyed on (business_id, trigger_type, match_text), so
 * shops keep every existing row exactly as it is — seller edits and
 * deliberately deactivated rules included — and simply gain the cases
 * they are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // match_text was created as varchar(255), but keyword rules carry
        // long comma-separated trigger lists (the longest library def is
        // 314 chars) and learned rules key on full customer phrasings.
        // On MySQL the backfill below fails with "Data too long" without
        // this widen; SQLite never enforced the limit, so widen first.
        Schema::table('ai_rules', function (Blueprint $table) {
            $table->text('match_text')->nullable()->change();
        });

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
