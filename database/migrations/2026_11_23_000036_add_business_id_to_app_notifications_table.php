<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifications become tenant-scoped (audit gap G3): every row
     * belongs to one shop via business_id. NULL means a platform
     * notice, visible to platform admins only.
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->index();
        });

        $this->backfillBusinessIds();
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex(['business_id']);
            $table->dropColumn('business_id');
        });
    }

    /**
     * Attribute existing rows to a shop where the link proves it:
     * order notifications link to /orders/{id} and the order carries
     * the business. Anything else (platform notices, work-inbox and
     * module links, links to orders that no longer exist) stays NULL.
     *
     * @return int rows attributed to a shop
     */
    public function backfillBusinessIds(): int
    {
        $updated = 0;

        DB::table('app_notifications')
            ->whereNull('business_id')
            ->where('link', 'like', '/orders/%')
            ->orderBy('id')
            ->each(function ($row) use (&$updated) {
                if (preg_match('#^/orders/(\d+)$#', (string) $row->link, $m) !== 1) {
                    return;
                }

                $biz = DB::table('orders')->where('id', (int) $m[1])->value('business_id');
                if ($biz) {
                    DB::table('app_notifications')->where('id', $row->id)
                        ->update(['business_id' => (int) $biz]);
                    $updated++;
                }
            });

        return $updated;
    }
};
