<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Core settings rows. firstOrCreate on purpose: re-seeding must never
 * reset a value the owner changed in the Settings UI.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['key' => 'security.totp_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'security'],
            ['key' => 'app.locale_default', 'value' => 'en', 'type' => 'string', 'group' => 'app'],
            ['key' => 'organization.name', 'value' => 'Scalenow Bd', 'type' => 'string', 'group' => 'organization'],
        ];

        foreach ($rows as $row) {
            Setting::firstOrCreate(['key' => $row['key']], $row);
        }
    }
}
