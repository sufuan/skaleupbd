<?php

// Goes in: app/Jobs/Integrations/PushWooOrderStatusJob.php (new file).
//
// Queued by WooOrderObserver when a WooCommerce-sourced order changes status.
// Pushes the new status (and tracking note if Shipped) to the WooCommerce REST API.
//
// Retry schedule: 1 m / 5 m / 30 m / 2 h / 12 h (same backoff as outbound webhook events).
// At zero points: job exits without pushing (zero-points rule — our services stop).
// Loop prevention: WooCommerceWriteBackService::push() checks last_pushed_status before sending.
//
// NEVER push while points == 0. NEVER push synchronously in a web request.

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceWriteBackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushWooOrderStatusJob implements ShouldQueue
{
    use Queueable;

    // Manual retry backoff (seconds): 1 m, 5 m, 30 m, 2 h, 12 h.
    // $tries = 1 so the queue itself does not retry; we re-dispatch manually with a delay.
    public int $tries   = 1;
    public int $timeout = 30;

    public function __construct(
        public readonly int    $connectionId,
        public readonly int    $orderId,
        public readonly string $ourStatus,
        public readonly int    $attempt = 1,
    ) {
    }

    public function handle(IntegrationHost $host, WooCommerceWriteBackService $service): void
    {
        $conn = IntegrationConnection::find($this->connectionId);

        if (! $conn || $conn->status === 'disabled') {
            return;
        }

        // --- Zero-points check (fail-closed; the host is wired).
        if (! $host->hasPoints((int) $conn->business_id)) {
            // Paused. The write-back is deferred - the reconciliation job
            // will retry outstanding pushes after top-up. Log and exit cleanly.
            Log::info('integration.woo.writeback.paused', [
                'connection_id' => $this->connectionId,
                'order_id'      => $this->orderId,
            ]);

            return;
        }

        // --- Attempt the push.
        $ok = $service->push($conn, $this->orderId, $this->ourStatus);

        if ($ok) {
            Log::info('integration.woo.writeback.ok', [
                'connection_id' => $this->connectionId,
                'order_id'      => $this->orderId,
                'status'        => $this->ourStatus,
            ]);

            return;
        }

        // --- Failed: schedule a retry if within the backoff window.
        $backoffs = [60, 300, 1800, 7200, 43200]; // 1m 5m 30m 2h 12h
        $nextIdx  = $this->attempt - 1; // attempt 1 → backoffs[0] = 60s

        if (isset($backoffs[$nextIdx])) {
            dispatch(new self(
                $this->connectionId,
                $this->orderId,
                $this->ourStatus,
                $this->attempt + 1,
            ))->delay(now()->addSeconds($backoffs[$nextIdx]));
        } else {
            // Exhausted retries. Mark connection degraded.
            $conn->increment('failure_count');
            $conn->update(['last_error' => 'Status push to WooCommerce failed after all retries.']);

            if ($conn->failure_count >= (int) config('integrations.webhook.disable_after_failures', 15)) {
                $conn->update(['status' => 'degraded']);
            }

            Log::error('integration.woo.writeback.dead', [
                'connection_id' => $this->connectionId,
                'order_id'      => $this->orderId,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('integration.woo.writeback.job_failed', [
            'connection_id' => $this->connectionId,
            'order_id'      => $this->orderId,
            'error'         => $e->getMessage(),
        ]);
    }
}
