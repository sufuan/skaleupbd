<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M18 merge (Claude redesign + our rules) — multi-page stores:
 * storefront_pages (draft vs published blocks, soft delete),
 * storefront_page_versions (last 5 published snapshots per page),
 * storefront_onboarding (wizard progress), storefront_daily_stats
 * (batched page views + orders), orders.storefront_page_id for
 * per-page attribution, api_keys webhook fields for Door 2.
 *
 * Data step: every existing storefront's blocks JSON becomes its
 * first page's published_blocks (legacy format — the public renderer
 * keeps rendering it exactly as before), so live shops are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->nullable(); // null = the store root page
            $table->string('template', 20)->default('store'); // single | store | offer
            $table->string('status', 20)->default('draft'); // draft | live | paused
            $table->json('draft_blocks')->nullable();
            $table->json('published_blocks')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->integer('sort')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['storefront_id', 'slug']);
        });

        Schema::create('storefront_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('storefront_pages')->cascadeOnDelete();
            $table->json('blocks')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_onboarding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('storefront_pages')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->timestamps();
            $table->unique(['page_id', 'date']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('storefront_page_id')->nullable()->index();
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret_enc')->nullable();
        });

        // ---- data: existing blocks -> first page (root) ----
        foreach (DB::table('storefronts')->get() as $sf) {
            $branding = json_decode((string) ($sf->branding ?? ''), true) ?: [];
            $status = match ($sf->status) {
                'published' => 'live',
                default => 'draft',
            };
            DB::table('storefront_pages')->insert([
                'storefront_id' => $sf->id,
                'business_id' => $sf->business_id,
                'name' => (string) ($branding['display_name'] ?? 'Home'),
                'slug' => null,
                'template' => 'store',
                'status' => $status,
                'draft_blocks' => $sf->blocks,
                'published_blocks' => $sf->blocks,
                'published_at' => $sf->published_at,
                'sort' => 0,
                'created_at' => $sf->created_at,
                'updated_at' => $sf->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        // Native DROP COLUMN on SQLite (its grammar double-quotes
        // these drops — a known bug); guarded so a partial state
        // can still roll back cleanly.
        $drop = function (string $table, string $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
            } else {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        };
        $drop('api_keys', 'webhook_url');
        $drop('api_keys', 'webhook_secret_enc');
        if (Schema::hasColumn('orders', 'storefront_page_id')) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS orders_storefront_page_id_index');
            } else {
                Schema::table('orders', fn (Blueprint $t) => $t->dropIndex(['storefront_page_id']));
            }
        }
        $drop('orders', 'storefront_page_id');
        Schema::dropIfExists('storefront_daily_stats');
        Schema::dropIfExists('storefront_onboarding');
        Schema::dropIfExists('storefront_page_versions');
        Schema::dropIfExists('storefront_pages');
    }
};
