<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Delivery-method names become per-business (couriers.{biz}.methods).
     * The legacy global list was built by the first shop's connections,
     * so it is copied to business 1's own key; every other shop starts
     * from its own connections only. The global key is left in place as
     * a historical record — nothing reads it any more.
     */
    public function up(): void
    {
        $settings = app(SettingsService::class);
        $legacy = $settings->get('couriers.methods');
        if (is_array($legacy) && $legacy !== [] && $settings->get('couriers.1.methods') === null) {
            $settings->set('couriers.1.methods', array_values($legacy));
        }
    }

    public function down(): void
    {
        // One-way data move; the per-business keys are harmless to keep.
    }
};
