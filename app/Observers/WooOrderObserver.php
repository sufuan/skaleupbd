<?php

// Goes in: app/Observers/WooOrderObserver.php (new file).
//
// Watches Order model saves and queues a status write-back to WooCommerce when:
//   1. The order's status changed.
//   2. The order has a matching row in external_orders linked to a WooCommerce connection.
//
// Register once in AppServiceProvider::boot():
//   \App\Models\Order::observe(\App\Observers\WooOrderObserver::class);
//
// IMPORTANT: Register this AFTER the existing OrderWebhookObserver so the outbound custom-site
// webhook fires first (or in any order — they are independent).
//
// This observer only queues a job. It never makes network calls inline.
// A crash inside the observer must never fail the seller's order save.

namespace App\Observers;

use App\Jobs\Integrations\PushWooOrderStatusJob;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WooOrderObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        try {
            $this->maybeQueuePush($order);
        } catch (\Throwable $e) {
            // Never let the integration layer break the seller's order update.
            Log::error('integration.woo.observer.error', ['error' => $e->getMessage()]);
            report($e);
        }
    }

    private function maybeQueuePush(Order $order): void
    {
        // Find a WooCommerce external_orders row for this order.
        $ext = DB::table('external_orders as eo')
            ->join('integration_connections as ic', 'ic.id', '=', 'eo.connection_id')
            ->where('eo.order_id', $order->getKey())
            ->where('ic.platform', 'woo')
            ->whereIn('ic.status', ['active', 'degraded'])
            ->first(['ic.id as connection_id']);

        if (! $ext) {
            return; // Not a WooCommerce order, or connection inactive.
        }

        $status = (string) $order->status;

        // Only push statuses that have a WooCommerce equivalent.
        if (! array_key_exists($status, \App\Services\Integrations\WooCommerceWriteBackService::PUSH_STATUS_MAP)) {
            return;
        }

        dispatch(new PushWooOrderStatusJob(
            connectionId: (int) $ext->connection_id,
            orderId:      (int) $order->getKey(),
            ourStatus:    $status,
        ));
    }
}
