<?php

// Goes in: database/migrations/ (new file, 000042). Additive only. Runs on MySQL and SQLite.
// Extends the existing api_keys table with two columns: scopes (JSON array) and revoked_at.
// Existing rows are given full access (scopes = ["*"]) so issued keys remain valid.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $t) {
            if (! Schema::hasColumn('api_keys', 'scopes')) {
                // JSON column; SQLite stores as text, MySQL as JSON.
                $t->text('scopes')->nullable()->after('last_used_at')
                    ->comment('JSON array of scope strings, e.g. ["*"] or ["orders.read","products.write"]');
            }
            if (! Schema::hasColumn('api_keys', 'revoked_at')) {
                $t->timestamp('revoked_at')->nullable()->after('scopes');
            }
        });

        // Back-fill existing rows so their keys remain fully functional.
        DB::table('api_keys')->whereNull('scopes')->update(['scopes' => json_encode(['*'])]);
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $t) {
            // dropColumn on SQLite 3.35+ is fine; older SQLite drivers silently ignore it.
            if (Schema::hasColumn('api_keys', 'revoked_at')) {
                $t->dropColumn('revoked_at');
            }
            if (Schema::hasColumn('api_keys', 'scopes')) {
                $t->dropColumn('scopes');
            }
        });
    }
};
