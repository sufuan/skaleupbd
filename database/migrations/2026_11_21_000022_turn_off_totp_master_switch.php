<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The TOTP master switch (security.totp_enabled) was found ON on the live
// site on 2026-11-21, locking the owner out behind a forced two-factor
// enrollment he never set up. The designed default is OFF (enrollment is
// opt-in from Settings → Security & Records). Restore the default.
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'security.totp_enabled')->exists();
        if ($exists) {
            DB::table('settings')->where('key', 'security.totp_enabled')
                ->update(['value' => '0', 'type' => 'boolean', 'updated_at' => now()]);
        } else {
            DB::table('settings')->insert([
                'key' => 'security.totp_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'security',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // One-way safety fix; no rollback.
    }
};
