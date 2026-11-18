<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sellers are the shops (businesses) that buy the platform's service.
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('owner_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('duration_days')->default(30);
            $table->string('description')->nullable();
            $table->json('features')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('seller_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('starts_at');
            $table->date('expires_at');
            $table->string('status')->default('active'); // active | expired | cancelled
            $table->timestamps();
        });

        Schema::create('seller_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('bKash');
            $table->string('note')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::create('customer_uploads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('file_name');
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->string('status')->default('Done');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_uploads');
        Schema::dropIfExists('seller_payments');
        Schema::dropIfExists('seller_subscriptions');
        Schema::dropIfExists('packages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['owner_name', 'phone', 'email', 'address']);
        });
    }
};
