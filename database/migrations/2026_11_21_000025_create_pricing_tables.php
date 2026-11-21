<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M19 Pricing — the points model. Sellers buy token packs; a token is
 * spent the moment an order enters the database and is never refunded.
 * This replaces the old time-tier packages as the sales model (the old
 * packages/seller_subscriptions tables stay as history only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->decimal('price_bdt', 12, 2);
            $table->unsignedInteger('credits');
            $table->string('validity_type', 20)->default('monthly'); // monthly | yearly_wallet
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('business_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->unique();
            $table->unsignedBigInteger('pack_id')->nullable();
            $table->json('pack_snapshot')->nullable();
            $table->integer('balance')->default(0);
            $table->timestamp('cycle_start')->nullable();
            $table->timestamp('cycle_end')->nullable();
            $table->timestamp('wallet_expires_at')->nullable();
            $table->timestamp('first_purchase_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('token_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('kind', 30); // purchase|deduct|reset|refund|manual_credit|adjust
            $table->integer('amount'); // signed; deductions negative
            $table->integer('balance_after');
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedInteger('points_per_order')->nullable();
            $table->string('note', 300)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Default packs (starting data only — everything is editable in
        // Settings → Seller Packages afterwards).
        $packs = [
            ['Startup Lite', 500, 330, 'monthly', 1],
            ['Startup Max', 2000, 1330, 'monthly', 2],
            ['Growth Basic', 2500, 1850, 'monthly', 3],
            ['Growth Plus', 3500, 2590, 'monthly', 4],
            ['Growth Pro', 4500, 3330, 'monthly', 5],
            ['Enterprise Entry Wallet', 6000, 4400, 'yearly_wallet', 6],
        ];
        foreach ($packs as [$name, $price, $credits, $type, $sort]) {
            DB::table('point_packs')->updateOrInsert(
                ['name' => $name],
                ['price_bdt' => $price, 'credits' => $credits, 'validity_type' => $type,
                    'is_active' => true, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Every existing business gets a wallet (0 balance — view-only
        // until a purchase, which IS the model).
        if (Schema::hasTable('businesses')) {
            foreach (DB::table('businesses')->pluck('id') as $bid) {
                DB::table('business_wallets')->insertOrIgnore([
                    'business_id' => $bid, 'balance' => 0, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Live safety: the platform owner's own shop (Business 1 /
            // demo-shop) must never be bricked by the new gate — grant it
            // a large manual credit through the ledger, like any credit.
            $ownerBiz = DB::table('businesses')->where('id', 1)->exists()
                ? 1
                : DB::table('businesses')->where('public_id', 'demo-shop')->value('id');
            if ($ownerBiz) {
                $already = DB::table('token_ledger')
                    ->where('idempotency_key', 'grant:platform-owner')->exists();
                if (! $already) {
                    DB::table('token_ledger')->insert([
                        'business_id' => $ownerBiz, 'kind' => 'manual_credit', 'amount' => 100000,
                        'balance_after' => 100000, 'note' => 'Platform owner grant',
                        'idempotency_key' => 'grant:platform-owner', 'created_at' => now(),
                    ]);
                    DB::table('business_wallets')->where('business_id', $ownerBiz)
                        ->update(['balance' => 100000, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('token_ledger');
        Schema::dropIfExists('business_wallets');
        Schema::dropIfExists('point_packs');
    }
};
