<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the "order created" marketing automation (the thank-you SMS flow)
 * off the request path. The flow sends through the seller's SMS gateway —
 * an external HTTP call that must never hold up order intake. One try
 * only: a retry after a gateway timeout could send the SMS twice.
 */
class OrderCreatedAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $orderId)
    {
    }

    public function handle(AutomationService $automation): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }
        try {
            $automation->onOrderCreated($order);
        } catch (\Throwable) {
            // Marketing must never break order intake — same rule as the
            // old synchronous call site in OrderService::create.
        }
    }
}
