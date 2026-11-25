<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // External API (api/external/*): 60 requests per minute per API
        // key. The bucket is keyed by a hash of the presented key, never
        // the key itself; keyless calls fall back to a per-IP bucket.
        RateLimiter::for('external-api', function (Request $request) {
            $key = trim((string) $request->header('x-api-key', ''));

            return $key !== ''
                ? Limit::perMinute(60)->by('api-key:'.hash('sha256', $key))
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        // Website integration: order status changes fan out to the
        // seller's own website (custom-site webhook + WooCommerce
        // write-back). Both observers only queue jobs; they never send
        // inline. Status changes made through OrderService save via
        // Eloquent, so the observers see every transition.
        \App\Models\Order::observe(\App\Observers\OrderWebhookObserver::class);
        \App\Models\Order::observe(\App\Observers\WooOrderObserver::class);

        // A new shop must never be rule-less: the observer installs the
        // Rule Brain's default intent rules on Business creation, which
        // is the single funnel for registration, admin creation and
        // every other creation path.
        \App\Models\Business::observe(\App\Observers\BusinessObserver::class);
    }
}
