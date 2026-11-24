<?php

declare(strict_types=1);

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceStockPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pushes stock quantity for one internal product to WooCommerce.
 *
 * Place in: app/Jobs/Integrations/PushWooStockJob.php
 *
 * Dispatch from wherever stock changes (order approved, delivered, manual adjust):
 *
 *   PushWooStockJob::dispatchDebounced($businessId, $productId);
 *
 * Debounce: before dispatching, check for an existing pending/delayed job for
 * the same (connection_id, product_id) pair in the jobs table and cancel it;
 * this coalesces rapid changes so we do one push instead of many.
 *
 * Zero-points: job aborts immediately if the shop has no points.
 */
final class PushWooStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;

    /** Backoff in seconds: 1 min, 5 min, 30 min, 2 h, 12 h */
    public array $backoff = [60, 300, 1800, 7200, 43200];

    public function __construct(
        private readonly int $connectionId,
        private readonly int $productId,
    ) {}

    // -------------------------------------------------------------------------

    /**
     * Dispatch with debounce: cancel any already-queued job for the same pair
     * before dispatching a fresh one with a small delay so rapid changes coalesce.
     */
    public static function dispatchDebounced(int $businessId, int $productId, int $delaySeconds = 30): void
    {
        // Find all active WooCommerce connections for this business.
        $connections = IntegrationConnection::query()
            ->where('business_id', $businessId)
            ->where('platform', 'woo')
            ->where('status', 'active')
            ->get(['id']);

        foreach ($connections as $conn) {
            // Delete any existing pending job for this pair to coalesce.
            DB::table('jobs')
                ->where('queue', config('queue.default', 'default'))
                ->where('payload', 'like', '%PushWooStockJob%')
                ->where('payload', 'like', '%"connectionId":' . $conn->id . '%')
                ->where('payload', 'like', '%"productId":' . $productId . '%')
                ->delete();

            // Dispatch a new one with a small delay so more changes can arrive.
            static::dispatch($conn->id, $productId)->delay(now()->addSeconds($delaySeconds));
        }
    }

    // -------------------------------------------------------------------------

    public function handle(IntegrationHost $host, WooCommerceStockPushService $stockPush): void
    {
        $conn = IntegrationConnection::find($this->connectionId);

        if (! $conn || $conn->status !== 'active' || $conn->platform !== 'woo') {
            Log::info('PushWooStockJob: connection gone or inactive, skipping.', [
                'connection_id' => $this->connectionId,
            ]);
            return;
        }

        // Zero-points rule: abort, do not retry (the reconciliation will handle
        // it after top-up via pushAll).
        if (! $host->pointsAvailable($conn->business_id)) {
            Log::info('PushWooStockJob: zero points, skipping stock push.', [
                'connection_id' => $this->connectionId,
                'product_id'    => $this->productId,
            ]);
            return;
        }

        $stockPush->pushProduct($conn, $this->productId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushWooStockJob: permanently failed.', [
            'connection_id' => $this->connectionId,
            'product_id'    => $this->productId,
            'error'         => $e->getMessage(),
        ]);
    }
}
