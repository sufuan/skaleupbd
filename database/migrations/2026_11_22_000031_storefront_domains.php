<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M18 merge §3.5: custom domains become their own rows — a seller
 * can connect more than one domain, one of them primary. Existing
 * single custom_domain values are copied across; the storefront
 * columns stay as a mirror of the primary row (admin screens and
 * the host resolver keep reading them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('status')->default('pending_dns'); // pending_dns | verified | active
            $table->string('txt_token')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
            $table->index(['storefront_id', 'status']);
        });

        foreach (DB::table('storefronts')->whereNotNull('custom_domain')->get() as $sf) {
            DB::table('storefront_domains')->insert([
                'business_id' => $sf->business_id,
                'storefront_id' => $sf->id,
                'domain' => $sf->custom_domain,
                'status' => $sf->domain_status === 'none' ? 'pending_dns' : $sf->domain_status,
                'txt_token' => $sf->domain_txt_token,
                'is_primary' => true,
                'verified_at' => $sf->domain_verified_at,
                'added_at' => $sf->domain_added_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_domains');
    }
};
