<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ধাপ ৭ Stage A — storefront phone-OTP brute-force guards, mirroring
 * the registration OTP (migration 000032): a wrong-guess counter for
 * the verify attempt cap and a last-sent stamp for the resend
 * cooldown. Both default to the "no code issued yet" state, so every
 * existing storefront row behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            if (! Schema::hasColumn('storefronts', 'otp_attempts')) {
                $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
            }
            if (! Schema::hasColumn('storefronts', 'otp_last_sent_at')) {
                $table->timestamp('otp_last_sent_at')->nullable()->after('otp_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $cols = ['otp_attempts', 'otp_last_sent_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('storefronts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
