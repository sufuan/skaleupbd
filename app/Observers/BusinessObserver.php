<?php

namespace App\Observers;

use App\Models\AiRule;
use App\Models\Business;
use Illuminate\Support\Facades\Schema;

/**
 * Every new shop gets the Rule Brain's default intent rules the moment
 * its Business row exists — registration, admin creation, factories and
 * imports all funnel through Eloquent creation, so this one hook covers
 * every runtime path. (DatabaseSeeder runs WithoutModelEvents by
 * design, so seeded shops get the library from MessagesSeeder instead;
 * any other shop is covered the first time the brain runs, by the
 * matchRule safety net.) Without these rows the brain can only fall
 * back to a generic price answer: it never bargains and never takes
 * an order. ensureDefaults is firstOrCreate-keyed, so re-runs and
 * shops that already have rules are untouched.
 */
class BusinessObserver
{
    public function created(Business $business): void
    {
        // Guarded like BusinessProvisioner's wallet seed: a Business row
        // created while migrations are still running (before the
        // ai_rules table exists) must not break the migration — the
        // backfill migration installs the defaults for such shops.
        if (! Schema::hasTable('ai_rules')) {
            return;
        }

        AiRule::ensureDefaults((int) $business->id);
    }
}
