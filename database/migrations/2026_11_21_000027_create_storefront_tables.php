<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M18 Storefront — one hosted storefront per business: subdomain or
 * custom domain address, blocks-JSON page, branding (incl. tracking),
 * phone-verification state for the publish gate, plan-lapse fields.
 * Plus the public "report this page" inbox and order attribution
 * columns (utm_*, fbclid, storefront_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subdomain')->nullable()->unique();
            $table->string('custom_domain')->nullable()->unique();
            // none | pending_dns | verified | active
            $table->string('domain_status', 20)->default('none');
            $table->string('domain_txt_token')->nullable();
            $table->timestamp('domain_verified_at')->nullable();
            $table->timestamp('domain_added_at')->nullable(); // admin marked "added in cPanel"
            // draft | published | taken_down
            $table->string('status', 20)->default('draft');
            $table->json('blocks')->nullable();
            $table->json('branding')->nullable();
            $table->json('settings')->nullable(); // delivery charges etc.
            $table->string('phone_to_verify')->nullable();
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('unpublished_reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('contact')->nullable();
            $table->string('page_url')->nullable();
            // open | resolved | dismissed
            $table->string('status', 20)->default('open');
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('fbclid')->nullable();
            $table->foreignId('storefront_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'storefront_id']);
        });
        Schema::dropIfExists('storefront_reports');
        Schema::dropIfExists('storefronts');
    }
};
