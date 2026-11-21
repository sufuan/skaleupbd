<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        // Meta test setup (Sufian's Lab, 2026-11-15) — ids prefilled, tokens
        // are pasted in Settings when the live connection is made.
        $settings = app(\App\Services\SettingsService::class);
        if ((string) $settings->get('meta.verify_token', '') === '') {
            $settings->set('meta.verify_token', 'skaleup_whk_test_2026');
        }
        $settings->set('meta.app_id', '2079470139380072');
        $fb = (array) $settings->get('channels.facebook', []);
        $settings->set('channels.facebook', array_merge(['account_id' => '1473954169130280', 'page_name' => "Sufian's Lab"], $fb));
        $wa = (array) $settings->get('channels.whatsapp', []);
        $settings->set('channels.whatsapp', array_merge(['account_id' => '1423806777488848', 'waba_id' => '1711674550353212', 'phone_number' => '+1 555 167 8599'], $wa));
        $demoBiz = Business::where('public_id', 'demo-shop')->value('id');
        if ($demoBiz) {
            $settings->set('channels.business_id', (int) $demoBiz);
        }

        // Platform owner account (no shop of its own).
        User::updateOrCreate(
            ['email' => 'platform@skaleup.test'],
            ['name' => 'Abu Sufian (Platform)', 'password' => 'password', 'email_verified_at' => now(), 'business_id' => null, 'is_admin' => true, 'is_platform_admin' => true],
        );

        // M19: the old time-tier packages are retired as a sales model.
        // The demo shops get points wallets with a local demo grant so
        // the seeded order flow works under the token gate (test data
        // only — live grants happen through the migration / purchases).
        $tokens = app(\App\Services\TokenService::class);

        $demo = Business::where('public_id', 'demo-shop')->first();
        $other = Business::where('public_id', 'other-shop')->first();

        if ($demo) {
            $demo->update(['owner_name' => 'Abu Sufian', 'phone' => '01712445566', 'email' => 'demo@shop.test', 'address' => 'Dhanmondi, Dhaka']);
            $tokens->ensureWallet($demo->id);
            if (! \App\Models\TokenLedger::where('business_id', $demo->id)->exists()) {
                $tokens->manualCredit($demo->id, 100000, 'Local demo grant', null);
            }
        }

        if ($other) {
            $other->update(['owner_name' => 'Tanvir Hasan', 'phone' => '01811223344', 'email' => 'other@shop.test', 'address' => 'Khulna']);
            $tokens->ensureWallet($other->id);
            if (! \App\Models\TokenLedger::where('business_id', $other->id)->exists()) {
                $tokens->manualCredit($other->id, 50000, 'Local demo grant', null);
            }
        }
    }
}
