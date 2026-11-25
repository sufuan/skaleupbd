<?php

use App\Models\Business;
use App\Services\BusinessProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the default manual courier method for every existing
 * business that has no courier connection at all. Until now a courier
 * method only came into existence when the seller added a connection
 * in Settings, so a fresh shop's New Order form offered an empty
 * Delivery Method list and the shop could not take its first order
 * (clean-slate Round 1 Phase 1 finding, 2026-11-26). New shops get
 * the method from BusinessProvisioner at registration; this covers
 * the shops that already exist. Shops with any courier method of
 * their own are untouched — ensureDefaultCourierMethod no-ops for
 * them, seller edits included.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courier_methods')) {
            return;
        }

        $provisioner = app(BusinessProvisioner::class);
        Business::query()->each(fn (Business $biz) => $provisioner->ensureDefaultCourierMethod($biz));
    }

    public function down(): void
    {
        // A backfilled default method is indistinguishable from one a
        // shop has since come to rely on for real orders; no safe
        // automatic undo.
    }
};
