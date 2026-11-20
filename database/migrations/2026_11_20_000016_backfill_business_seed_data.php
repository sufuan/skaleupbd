<?php

use App\Models\Business;
use App\Services\BusinessProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Shops created before BusinessProvisioner existed (e.g. repaired by
     * hand on the server) can be missing their default order sources,
     * message templates, finance categories or Cash account — which makes
     * core screens unusable (the order form cannot be submitted without a
     * source). Backfill every existing shop; all steps are idempotent.
     */
    public function up(): void
    {
        $provisioner = app(BusinessProvisioner::class);
        Business::query()->each(fn (Business $biz) => $provisioner->ensureSeeds($biz));
    }

    public function down(): void
    {
        // Data backfill only; nothing to roll back.
    }
};
