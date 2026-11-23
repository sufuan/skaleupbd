<?php

// Goes in: app/Observers/OrderWebhookObserver.php (new file).
// Register once in AppServiceProvider::boot():  \App\Models\Order::observe(\App\Observers\OrderWebhookObserver::class);
// Note: bulk query-builder updates skip observers. If OrderService changes status in bulk, call
// app(OutboundWebhookService::class)->orderStatusChanged($order) for each order there.

namespace App\Observers;

use App\Models\Order;
use App\Services\Integrations\OutboundWebhookService;

class OrderWebhookObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        // The seller's order update must never fail or slow down because of the integration layer.
        try {
            app(OutboundWebhookService::class)->orderStatusChanged($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
