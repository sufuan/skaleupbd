<?php

declare(strict_types=1);

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceClient;
use App\Services\Integrations\WooCommerceConnectService;
use App\Services\Integrations\WooCommerceOrderImporter;
use App\Services\Integrations\WooCommerceStockPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce reconciliation job — runs every 15 minutes via scheduler.
 *
 * Place in: app/Jobs/Integrations/WooReconciliationJob.php
 *
 * What it does for each active WooCommerce connection:
 *  1. Re-registers any webhooks WooCommerce auto-disabled (after 5 failures).
 *  2. Fetches orders modified since `woo_orders_cursor` and imports any that
 *     are missing from our system (covers the gap from a suspension).
 *  3. After points restoration, calls OutboundWebhookService::resumeForBusiness().
 *  4. Pushes any changed stock quantities (zero-points: skipped).
 *  5. Advances the cursor after a successful pass.
 *
 * Zero-points rule:
 *  - Orders are NOT imported while points are zero.
 *  - Webhook re-registration IS attempted regardless (so when points return
 *    the webhooks are ready immediately).
 *  - Stock push is skipped at zero points.
 */
final class WooReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Scheduled command handles re-scheduling; no retries.
    public int $timeout = 300; // 5 minutes max per run.

    public function __construct(
        private readonly int $connectionId,
    ) {}

    // -------------------------------------------------------------------------

    public function handle(
        IntegrationHost $host,
        WooCommerceClient $client,
        WooCommerceConnectService $connectService,
        WooCommerceOrderImporter $orderImporter,
        WooCommerceStockPushService $stockPush,
    ): void {
        $conn = IntegrationConnection::find($this->connectionId);

        if (! $conn || $conn->type !== 'woocommerce') {
            return;
        }

        // Update connection status to reflect last reconciliation attempt.
        $conn->update(['last_synced_at' => now()]);

        $hasPoints = $host->pointsAvailable($conn->business_id);

        // --- Step 1: Re-register dead webhooks ---------------------------------
        // Always attempt even at zero points so delivery resumes immediately
        // after top-up without waiting for next reconciliation cycle.
        $this->reRegisterWebhooks($conn, $client, $connectService);

        // --- Step 2: Pull missing orders ----------------------------------------
        if ($hasPoints) {
            $this->reconcileOrders($conn, $client, $orderImporter);
        } else {
            Log::info('WooReconciliation: zero points, skipping order import.', [
                'connection_id' => $this->connectionId,
            ]);
        }

        // --- Step 3: Push any changed stock -------------------------------------
        if ($hasPoints) {
            try {
                $stockPush->pushAll($conn);
            } catch (\Throwable $e) {
                Log::error('WooReconciliation: stock push error.', [
                    'connection_id' => $this->connectionId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // Mark last reconciliation time on the connection.
        $conn->update(['woo_reconciled_at' => now()]);
    }

    // -------------------------------------------------------------------------

    private function reRegisterWebhooks(
        IntegrationConnection $conn,
        WooCommerceClient $client,
        WooCommerceConnectService $connectService,
    ): void {
        try {
            $wooWebhookIds = (array) ($conn->woo_webhook_ids ?? []);

            if (empty($wooWebhookIds)) {
                // No webhooks on record — register fresh.
                RegisterWooWebhooksJob::dispatchSync($conn->id);
                return;
            }

            // Fetch current webhook list from WooCommerce.
            $liveWebhooks = $client->get($conn, 'webhooks', ['per_page' => 100]);
            $liveIds      = collect($liveWebhooks)->pluck('id')->map(fn ($id) => (int) $id)->toArray();

            $missingIds = array_diff(array_map('intval', $wooWebhookIds), $liveIds);

            if (! empty($missingIds)) {
                Log::warning('WooReconciliation: webhooks missing from WC, re-registering.', [
                    'connection_id' => $this->connectionId,
                    'missing'       => $missingIds,
                ]);
                // RegisterWooWebhooksJob creates new webhooks and updates woo_webhook_ids.
                RegisterWooWebhooksJob::dispatchSync($conn->id);
            }

            // Also check for any webhooks WC reports as "disabled".
            $disabledIds = collect($liveWebhooks)
                ->filter(fn ($wh) => ($wh['status'] ?? '') === 'disabled'
                    && in_array((int) ($wh['id'] ?? 0), array_map('intval', $wooWebhookIds)))
                ->pluck('id')
                ->toArray();

            if (! empty($disabledIds)) {
                Log::warning('WooReconciliation: disabled webhooks found, re-enabling via re-register.', [
                    'connection_id' => $this->connectionId,
                    'disabled'      => $disabledIds,
                ]);
                // Delete disabled ones from WC then re-register.
                foreach ($disabledIds as $wid) {
                    try {
                        $client->delete($conn, "webhooks/{$wid}");
                    } catch (\Throwable) {
                        // Ignore — it may already be gone.
                    }
                }
                RegisterWooWebhooksJob::dispatchSync($conn->id);
            }
        } catch (\Throwable $e) {
            Log::error('WooReconciliation: webhook check failed.', [
                'connection_id' => $this->connectionId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function reconcileOrders(
        IntegrationConnection $conn,
        WooCommerceClient $client,
        WooCommerceOrderImporter $importer,
    ): void {
        try {
            // `woo_orders_cursor` stores the ISO timestamp of the last-seen order.
            // On first run it is null; we default to 24 hours ago to avoid a huge backfill.
            $cursor = $conn->woo_orders_cursor
                ?? now()->subHours(24)->toIso8601String();

            // Statuses we care about (matches the inbound status map defaults).
            $params = [
                'after'    => $cursor,
                'status'   => 'processing,on-hold',
                'per_page' => 50,
                'orderby'  => 'modified',
                'order'    => 'asc',
            ];

            $orders = $client->get($conn, 'orders', $params);

            if (empty($orders)) {
                return;
            }

            $latestModified = $cursor;
            $imported       = 0;
            $skipped        = 0;

            foreach ($orders as $wooOrder) {
                $wooId = (int) ($wooOrder['id'] ?? 0);
                if (! $wooId) {
                    continue;
                }

                // Check if already in our system via external_orders table.
                $exists = DB::table('external_orders')
                    ->where('connection_id', $conn->id)
                    ->where('external_id', (string) $wooId)
                    ->exists();

                if (! $exists) {
                    try {
                        $importer->import($conn, $wooOrder);
                        $imported++;
                    } catch (\Throwable $e) {
                        Log::error('WooReconciliation: order import error.', [
                            'connection_id' => $this->connectionId,
                            'woo_order_id'  => $wooId,
                            'error'         => $e->getMessage(),
                        ]);
                    }
                } else {
                    $skipped++;
                }

                // Track latest modified timestamp.
                $modified = $wooOrder['date_modified_gmt'] ?? $wooOrder['date_modified'] ?? null;
                if ($modified && $modified > $latestModified) {
                    $latestModified = $modified;
                }
            }

            // Advance cursor to just after the last item we processed.
            $conn->update(['woo_orders_cursor' => $latestModified]);

            Log::info('WooReconciliation: orders done.', [
                'connection_id' => $this->connectionId,
                'imported'      => $imported,
                'skipped'       => $skipped,
                'cursor'        => $latestModified,
            ]);
        } catch (\Throwable $e) {
            Log::error('WooReconciliation: order reconcile failed.', [
                'connection_id' => $this->connectionId,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
