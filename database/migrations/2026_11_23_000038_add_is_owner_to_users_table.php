<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identity separation (ধাপ ১): a shop's owner gets an honest marker.
     * Until now "owner" was spelled two wrong ways: the is_admin flag
     * (which no production code path ever set, so real owners did not
     * have it) and role = 'admin' (which is also an HRM staff-role
     * label). is_owner is the single owner marker; role stays as the
     * staff-role substrate for ধাপ ৪.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('is_platform_admin');
        });

        // Backfill: within a business, role 'admin' has always meant
        // the owner (registration creates owners exactly that way).
        // Platform admins are never owners of a shop.
        DB::table('users')
            ->where('role', 'admin')
            ->whereNotNull('business_id')
            ->update(['is_owner' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
