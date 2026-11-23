<?php

// Goes in: app/Services/Integrations/WooCommerceConnectService.php (new file).
// Orchestrates the full WooCommerce connect flow:
//   1. Validate credentials shape
//   2. Test the connection (queued job calls this)
//   3. Register the four webhooks on the seller's site
//   4. Kick off the product import job
//
// All calls to the seller's site go through WooCommerceClient (which uses Guzzle with
// the same no-redirect, TLS-verify, timeout settings as SafeHttp).
// Never call this directly from a web request — the controller queues TestWooConnectionJob.

namespace App\Services\Integrations;

use App\Jobs\Integrations\RegisterWooWebhooksJob;
use App\Jobs\Integrations\ImportWooProductsJob;
use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WooCommerceConnectService
{
    // The four webhook topics we register on every WooCommerce site.
    public const WEBHOOK_TOPICS = [
        'order.created',
        'order.updated',
        'product.created',
        'product.updated',
    ];

    // Default WooCommerce status → our status.
    // null means: skip this WooCommerce status (do not import the order).
    public const DEFAULT_STATUS_MAP = [
        'processing' => 'Processing',
        'on-hold'    => 'Pending',
    ];

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly IntegrationHost   $host,
    ) {
    }

    // ------------------------------------------------------------------ connect flow

    /**
     * Save the WooCommerce credentials and queue the connection test + webhook registration.
     * Called by WooConnectController::store().
     * Returns the connection (status = pending until the test job completes).
     *
     * Credentials shape:  { ck: string, cs: string }   (consumer key, consumer secret)
     */
    public function saveAndQueue(int $businessId, string $siteUrl, string $ck, string $cs): IntegrationConnection
    {
        // Validate the site URL shape (no DNS; that happens in the job).
        SafeHttp::validateShape($siteUrl);

        // Upsert the connection row. One WooCommerce connection per shop.
        $conn = IntegrationConnection::query()
            ->forBusiness($businessId)
            ->where('platform', 'woo')
            ->first();

        // Generate a new webhook secret and unguessable public token every time credentials are updated.
        $secret = 'whsec_' . Str::random(40);
        $token  = Str::random(48);

        $data = [
            'business_id'         => $businessId,
            'platform'            => 'woo',
            'site_url'            => rtrim($siteUrl, '/'),
            'status'              => 'pending',
            'credentials_enc'     => ['ck' => $ck, 'cs' => $cs],
            'webhook_secret_enc'  => $secret,
            'webhook_secret_last4'=> substr($secret, -4),
            'public_token'        => $token,
            'failure_count'       => 0,
            'last_error'          => null,
            'woo_status_map'      => json_encode(self::DEFAULT_STATUS_MAP),
            'woo_webhook_ids'     => json_encode([]),
        ];

        if ($conn) {
            $conn->fill($data)->save();
        } else {
            $conn = IntegrationConnection::create($data);
        }

        // Queue the test + webhook registration job.
        dispatch(new RegisterWooWebhooksJob($conn->id));

        return $conn->fresh();
    }

    // ------------------------------------------------------------------ webhook registration (called from job)

    /**
     * Run inside RegisterWooWebhooksJob. Tests the connection, registers webhooks, marks active.
     * Returns array of check results for the UI.
     */
    public function connectAndRegister(IntegrationConnection $conn): array
    {
        $creds = $conn->credentials_enc;
        $ck    = (string) ($creds['ck'] ?? '');
        $cs    = (string) ($creds['cs'] ?? '');
        $checks = [];

        // --- Step 1: Test credentials
        try {
            $this->client->testConnection($conn->site_url, $ck, $cs);
            $checks['credentials'] = ['ok' => true, 'message' => 'Connected to your WooCommerce store.'];
        } catch (\Throwable $e) {
            $checks['credentials'] = ['ok' => false, 'message' => $this->friendlyError($e)];
            $this->markFailed($conn, $checks['credentials']['message']);

            return $checks;
        }

        // --- Step 2: Delete any stale webhooks from a previous connection attempt
        $existingIds = json_decode((string) ($conn->woo_webhook_ids ?? '[]'), true) ?? [];
        foreach ($existingIds as $wid) {
            try {
                $this->client->deleteWebhook($conn->site_url, $ck, $cs, (int) $wid);
            } catch (\Throwable) {
                // ignore — it may already be gone
            }
        }

        // --- Step 3: Register the four webhooks
        $deliveryBase = config('app.url') . '/api/webhooks/woo/' . $conn->public_token;
        $secret       = (string) $conn->webhook_secret_enc;
        $registeredIds = [];

        foreach (self::WEBHOOK_TOPICS as $topic) {
            try {
                $wid = $this->client->createWebhook(
                    $conn->site_url, $ck, $cs,
                    $topic,
                    $deliveryBase . '/' . str_replace('.', '/', $topic),
                    $secret,
                );
                $registeredIds[] = $wid;
            } catch (\Throwable $e) {
                $checks['webhooks'] = ['ok' => false, 'message' => $this->friendlyError($e)];
                $this->markFailed($conn, $checks['webhooks']['message']);

                return $checks;
            }
        }

        $checks['webhooks'] = ['ok' => true, 'message' => 'Webhooks registered on your store.'];

        // --- Step 4: Mark active, store registered webhook IDs
        $conn->update([
            'status'          => 'active',
            'woo_webhook_ids' => json_encode($registeredIds),
            'failure_count'   => 0,
            'last_error'      => null,
            'last_success_at' => now(),
        ]);

        // --- Step 5: Kick off product import
        dispatch(new ImportWooProductsJob($conn->id));
        $checks['products'] = ['ok' => true, 'message' => 'Product import started.'];

        return $checks;
    }

    // ------------------------------------------------------------------ disconnect

    public function disconnect(IntegrationConnection $conn): void
    {
        $creds = $conn->credentials_enc ?? [];
        $ck    = (string) ($creds['ck'] ?? '');
        $cs    = (string) ($creds['cs'] ?? '');

        $ids = json_decode((string) ($conn->woo_webhook_ids ?? '[]'), true) ?? [];
        foreach ($ids as $wid) {
            try {
                $this->client->deleteWebhook($conn->site_url, $ck, $cs, (int) $wid);
            } catch (\Throwable) {
            }
        }

        $conn->update([
            'status'          => 'disabled',
            'woo_webhook_ids' => json_encode([]),
            'credentials_enc' => null,
            'last_error'      => 'Disconnected by seller.',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    public function statusMap(IntegrationConnection $conn): array
    {
        $raw = $conn->woo_status_map;
        if (! $raw) {
            return self::DEFAULT_STATUS_MAP;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : self::DEFAULT_STATUS_MAP;
    }

    private function markFailed(IntegrationConnection $conn, string $error): void
    {
        $conn->update([
            'status'       => 'failed',
            'last_error'   => $error,
            'failure_count'=> $conn->failure_count + 1,
        ]);
    }

    private function friendlyError(\Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, '401') || str_contains($m, '403')
                => 'Your WooCommerce key or secret is wrong. Check the credentials in WooCommerce > Settings > Advanced > REST API.',
            str_contains($m, '404')
                => 'Could not find the WooCommerce REST API on your site. Make sure WooCommerce is installed and pretty permalinks are on.',
            str_contains($m, 'cURL error 6') || str_contains($m, 'Could not resolve')
                => 'Could not reach your site. Check that the URL is correct and the site is online.',
            str_contains($m, 'cURL error 60') || str_contains($m, 'SSL')
                => 'Your site\'s SSL certificate could not be verified. Check it is valid and not expired.',
            str_contains($m, 'timed out') || str_contains($m, 'cURL error 28')
                => 'Your site took too long to respond. Try again, or check your site is running.',
            default => 'Could not connect: ' . substr($m, 0, 180),
        };
    }
}
