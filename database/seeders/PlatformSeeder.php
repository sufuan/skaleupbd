<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Package;
use App\Models\SellerPayment;
use App\Models\SellerSubscription;
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

        $starter = Package::updateOrCreate(['name' => 'Starter'], [
            'price' => 999, 'duration_days' => 30, 'sort' => 1, 'active' => true,
            'description' => 'For new shops starting online',
            'features' => ['orders', 'delivery', 'customers', 'reports'],
        ]);
        $growth = Package::updateOrCreate(['name' => 'Growth'], [
            'price' => 1999, 'duration_days' => 30, 'sort' => 2, 'active' => true,
            'description' => 'For growing F-commerce sellers',
            'features' => ['orders', 'delivery', 'inventory', 'messages', 'ai_reply', 'fraud_check', 'work_inbox', 'customers', 'reports'],
        ]);
        Package::updateOrCreate(['name' => 'Business Pro'], [
            'price' => 3999, 'duration_days' => 30, 'sort' => 3, 'active' => true,
            'description' => 'For teams with high order volume',
            'features' => \App\Support\FeatureCatalog::keys(),
        ]);

        $demo = Business::where('public_id', 'demo-shop')->first();
        $other = Business::where('public_id', 'other-shop')->first();

        if ($demo) {
            $demo->update(['owner_name' => 'Abu Sufian', 'phone' => '01712445566', 'email' => 'demo@shop.test', 'address' => 'Dhanmondi, Dhaka']);
            $sub = SellerSubscription::updateOrCreate(
                ['business_id' => $demo->id, 'package_id' => $growth->id],
                ['amount' => 1999, 'starts_at' => today()->subDays(9), 'expires_at' => today()->addDays(20), 'status' => 'active'],
            );
            SellerPayment::updateOrCreate(
                ['business_id' => $demo->id, 'subscription_id' => $sub->id],
                ['amount' => 1999, 'method' => 'bKash', 'note' => 'Growth package', 'paid_at' => today()->subDays(9)],
            );
        }

        if ($other) {
            $other->update(['owner_name' => 'Tanvir Hasan', 'phone' => '01811223344', 'email' => 'other@shop.test', 'address' => 'Khulna']);
            $sub = SellerSubscription::updateOrCreate(
                ['business_id' => $other->id, 'package_id' => $starter->id],
                ['amount' => 999, 'starts_at' => today()->subDays(35), 'expires_at' => today()->subDays(6), 'status' => 'expired'],
            );
            SellerPayment::updateOrCreate(
                ['business_id' => $other->id, 'subscription_id' => $sub->id],
                ['amount' => 999, 'method' => 'Nagad', 'note' => 'Starter package', 'paid_at' => today()->subDays(35)],
            );
        }
    }
}
