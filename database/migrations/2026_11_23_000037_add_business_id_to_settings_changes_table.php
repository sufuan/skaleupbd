<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings change history becomes attributable: every new row records
     * the acting user's business (NULL for platform admins acting on
     * platform-global keys). Settings → Change History then shows a
     * seller only their own shop's rows; rows written before this column
     * existed stay NULL and remain platform-admin only.
     */
    public function up(): void
    {
        Schema::table('settings_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('settings_changes', function (Blueprint $table) {
            $table->dropIndex(['business_id']);
            $table->dropColumn('business_id');
        });
    }
};
