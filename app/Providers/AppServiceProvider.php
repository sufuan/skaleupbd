<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Website integration: order status changes fan out to the
        // seller's own website (custom-site webhook + WooCommerce
        // write-back). Both observers only queue jobs; they never send
        // inline. Status changes made through OrderService save via
        // Eloquent, so the observers see every transition.
        \App\Models\Order::observe(\App\Observers\OrderWebhookObserver::class);
        \App\Models\Order::observe(\App\Observers\WooOrderObserver::class);
    }
}
