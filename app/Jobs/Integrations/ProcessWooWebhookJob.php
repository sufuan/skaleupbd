<?php

// Goes in: app/Jobs/Integrations/ProcessWooWebhookJob.php (new file).
// Dispatched by WooWebhookController immediately after the event is stored.
// Checks the shop's point balance before doing anything.
// At zero points: logs "paused", leaves the event in "received" state, job exits.
// SweepWebhookEvents (the existing every-minute sweeper) re-queues received inbound events
// for shops that have topped up — the same recovery path as outbound.
//
// Order events:  → WooCommerceOrderImporter::importOrder()
// Product events: → update external_products (name/SKU/hash changes from the site)

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceConnectService;
use App\Services\Integrations\WooCommerceOrderImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWooWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;    // failure is recorded on the event row; sweeper re-queues
    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
    }

    public function handle(
        IntegrationHost        $host,
        WooCommerceOrderImporter $importer,
    ): void {
        $event = IntegrationEvent::find($this->eventId);

        if (! $event || $event->status !== 'received') {
            return; // already processed or dead
        }

        $conn = IntegrationConnection::find($event->connection_id);

        if (! $conn || $conn->status === 'disabled') {
            $this->markSkipped($event, 'Connection is disabled.');

            return;
        }

        // --- Zero-points check (the owner's rule). IntegrationHost is
        // wired to the real wallet, so this is fail-closed by construction.
        if (! $host->hasPoints((int) $conn->business_id)) {
            // Leave in "received" - sweeper will re-queue after top-up.
            $event->update(['error' => 'paused_no_points']);
            Log::info('integration.woo.inbound.paused', ['event_id' => $this->eventId]);

            return;
        }

        // --- Parse payload
        $payload = json_decode((string) $event->payload, true);
        if (! is_array($payload)) {
            $this->markFailed($event, 'Payload is not valid JSON.');

            return;
        }

        // --- Dispatch by topic
        try {
            match ($event->topic) {
                'order.created', 'order.updated' => $this->handleOrder($conn, $event, $payload, $importer),
                'product.created', 'product.updated' => $this->handleProduct($conn, $event, $payload),
                default => $this->markSkipped($event, 'Unknown topic: ' . $event->topic),
            };
        } catch (\Throwable $e) {
            $this->markFailed($event, substr($e->getMessage(), 0, 480));
            Log::error('integration.woo.inbound.error', [
                'event_id' => $this->eventId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------ order handler

    private function handleOrder(
        IntegrationConnection    $conn,
        IntegrationEvent         $event,
        array                    $payload,
        WooCommerceOrderImporter $importer,
    ): void {
        // Loop prevention (README wiring item 3): an order.updated whose
        // status equals the one we last pushed to WooCommerce is the echo
        // of our own write-back - skip it instead of re-importing.
        if ($event->topic === 'order.updated') {
            $wooId = (int) ($payload['id'] ?? 0);
            $wooSt = (string) ($payload['status'] ?? '');
            if (\App\Services\Integrations\WooCommerceWriteBackService::isEcho($conn->id, $wooId, $wooSt)) {
                $this->markSkipped($event, 'Echo of our own status push - skipped.');

                return;
            }
        }

        $statusMap = json_decode((string) ($conn->woo_status_map ?? ''), true)
            ?? WooCommerceConnectService::DEFAULT_STATUS_MAP;

        $orderId = $importer->importOrder($conn, $payload, $statusMap);

        // Update subject_id so the event can be correlated with the order.
        $event->update([
            'status'     => 'processed',
            'subject_id' => $orderId,
            'attempts'   => $event->attempts + 1,
            'error'      => null,
        ]);
    }

    // ------------------------------------------------------------------ product handler

    private function handleProduct(IntegrationConnection $conn, IntegrationEvent $event, array $payload): void
    {
        $wooProductId = (int) ($payload['id'] ?? 0);
        $sku          = (string) ($payload['sku'] ?? '');
        $type         = (string) ($payload['type'] ?? 'simple');

        if ($wooProductId === 0) {
            $this->markSkipped($event, 'Product payload missing id.');

            return;
        }

        $hash = sha1(json_encode([$sku, $payload['name'] ?? null]));
        $now  = now();

        // Simple product upsert.
        $ext = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->where('external_id', (string) $wooProductId)
            ->where('external_variation_id', '')
            ->first(['id', 'state', 'hash']);

        if (! $ext) {
            $productId = $sku ? DB::table('products')
                ->where('business_id', $conn->business_id)
                ->where('sku', $sku)
                ->value('id') : null;

            DB::table('external_products')->insert([
                'connection_id'         => $conn->id,
                'external_id'           => (string) $wooProductId,
                'external_variation_id' => '',
                'sku'                   => $sku ?: null,
                'product_id'            => $productId,
                'state'                 => $productId ? 'matched' : 'unmatched',
                'hash'                  => $hash,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } elseif ($ext->hash !== $hash && $ext->state !== 'matched') {
            DB::table('external_products')->where('id', $ext->id)->update([
                'sku'        => $sku ?: null,
                'hash'       => $hash,
                'updated_at' => $now,
            ]);
        }

        $event->update([
            'status'     => 'processed',
            'subject_id' => $wooProductId,
            'attempts'   => $event->attempts + 1,
            'error'      => null,
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function markFailed(IntegrationEvent $event, string $error): void
    {
        $event->update([
            'status'   => 'failed',
            'attempts' => $event->attempts + 1,
            'error'    => $error,
        ]);
    }

    private function markSkipped(IntegrationEvent $event, string $reason): void
    {
        $event->update([
            'status' => 'skipped',
            'error'  => $reason,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        IntegrationEvent::whereKey($this->eventId)->update([
            'status' => 'failed',
            'error'  => substr($e->getMessage(), 0, 480),
        ]);
    }
}
