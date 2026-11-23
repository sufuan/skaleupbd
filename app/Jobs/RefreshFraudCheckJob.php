<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\SuccessRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Completes an order's fraud snapshot with the courier-network figures.
 * Order intake (and the order detail page) never wait on a courier API:
 * they store the local-history verdict plus any already-cached network
 * result and queue this job, which runs the live check off the request
 * path and writes the outcome onto the order.
 * Unique per order: repeated views of the same order queue one refresh.
 */
class RefreshFraudCheckJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public function __construct(public int $orderId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(SuccessRateService $fraud): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }
        $fraud->refreshOrderSnapshot($order);
        try {
            // The snapshot is final now: give auto-approve its chance to
            // move a trusted customer's order straight to Ready to Ship.
            app(\App\Services\AutoApproveService::class)->evaluate($order);
        } catch (\Throwable) {
            // Auto-approve must never fail the snapshot refresh.
        }
    }
}
