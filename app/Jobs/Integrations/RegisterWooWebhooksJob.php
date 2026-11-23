<?php

// Goes in: app/Jobs/Integrations/RegisterWooWebhooksJob.php (new file).
// Queued by WooCommerceConnectService::saveAndQueue().
// Tests the WooCommerce connection, registers the four webhooks, and kicks off the product import.
// All network calls happen here, never in a web request.

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\WooCommerceConnectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegisterWooWebhooksJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;    // connection failure is surfaced to the UI; we don't auto-retry
    public int $timeout = 60;

    public function __construct(public int $connectionId)
    {
    }

    public function handle(WooCommerceConnectService $service): void
    {
        $conn = IntegrationConnection::find($this->connectionId);

        if (! $conn || $conn->status === 'disabled') {
            return;
        }

        try {
            $checks = $service->connectAndRegister($conn);
            Log::info('integration.woo.connect', [
                'connection_id' => $this->connectionId,
                'checks'        => array_map(fn ($c) => $c['ok'], $checks),
            ]);
        } catch (\Throwable $e) {
            Log::error('integration.woo.connect.failed', [
                'connection_id' => $this->connectionId,
                'error'         => $e->getMessage(),
            ]);
            $conn->update([
                'status'       => 'failed',
                'last_error'   => substr($e->getMessage(), 0, 480),
                'failure_count'=> $conn->failure_count + 1,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        IntegrationConnection::where('id', $this->connectionId)->update([
            'status'     => 'failed',
            'last_error' => substr($e->getMessage(), 0, 480),
        ]);
    }
}
