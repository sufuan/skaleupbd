<?php

use App\Services\StaffRoleBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff & Roles (ধাপ ৪): seller-defined roles with four-tick
 * permissions, one role per staff user, plus the audit log.
 * The data step backfills every existing shop: each staff member's
 * current module access becomes a role ("Old - Manager" etc.) so
 * nobody's access changes on day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('role_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });

        // Day-one backfill for shops that already have staff.
        if (Schema::hasTable('businesses')) {
            $backfill = app(StaffRoleBackfill::class);
            foreach (\App\Models\Business::pluck('id') as $businessId) {
                $backfill->forBusiness((int) $businessId);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('role_change_logs');
        Schema::dropIfExists('roles');
    }
};
