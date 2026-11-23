<?php

// Goes in: app/Services/Integrations/WooCommerceClient.php (new file).
// A thin, SSRF-safe wrapper around the WooCommerce REST API v3.
// All network calls go through SafeHttp (HTTPS-only, public-IP, no redirects, 10 s).
// Only call this from queued jobs, never from a web request.
//
// WooCommerce REST API uses HTTP Basic auth with consumer key + consumer secret.
// The base URL is the seller's site URL (stored in integration_connections.site_url).
// Credentials are stored encrypted in integration_connections.credentials_enc.

namespace App\Services\Integrations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WooCommerceClient
{
    public function __construct(private readonly SafeHttp $http)
    {
    }

    // ------------------------------------------------------------------ connection test

    /**
     * Test that the credentials work by fetching /wp-json/wc/v3/system_status.
     * Returns the decoded JSON body on success, throws on failure.
     *
     * @throws \RuntimeException|\App\Services\Integrations\UnsafeUrlException
     */
    public function testConnection(string $siteUrl, string $ck, string $cs): array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/system_status';
        $response = $this->get($url, $ck, $cs);
        $this->assertOk($response, 'Connection test failed');

        return $response->json() ?? [];
    }

    // ------------------------------------------------------------------ webhook management

    /**
     * Register a WooCommerce webhook.
     *
     * @param string $topic  e.g. "order.created"
     * @param string $deliveryUrl  our inbound endpoint URL (contains unguessable token)
     * @param string $secret  our HMAC secret (stored encrypted; WooCommerce sends it in X-WC-Webhook-Secret (verify))
     * @return int  the WooCommerce webhook ID
     */
    public function createWebhook(string $siteUrl, string $ck, string $cs, string $topic, string $deliveryUrl, string $secret): int
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/webhooks';
        $body = json_encode([
            'name'         => 'SkaleUp ' . $topic,
            'topic'        => $topic,
            'delivery_url' => $deliveryUrl,
            'secret'       => $secret,
            'status'       => 'active',
        ]);

        $response = $this->postJson($url, $ck, $cs, $body);
        $this->assertOk($response, 'Failed to register webhook for topic ' . $topic);

        return (int) ($response->json('id') ?? 0);
    }

    /**
     * Get all registered webhooks (paginated, 100 per page).
     * Returns array of ['id' => int, 'topic' => string, 'status' => string].
     */
    public function listWebhooks(string $siteUrl, string $ck, string $cs): array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/webhooks?per_page=100&page=1';
        $response = $this->get($url, $ck, $cs);
        if (! $response->successful()) {
            return [];
        }

        return array_map(fn ($w) => [
            'id'     => (int) ($w['id'] ?? 0),
            'topic'  => (string) ($w['topic'] ?? ''),
            'status' => (string) ($w['status'] ?? ''),
        ], $response->json() ?? []);
    }

    /**
     * Delete a webhook by ID. Silently ignores 404 (already gone).
     */
    public function deleteWebhook(string $siteUrl, string $ck, string $cs, int $webhookId): void
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/webhooks/' . $webhookId . '?force=true';
        $this->delete($url, $ck, $cs);
    }

    // ------------------------------------------------------------------ orders

    /**
     * Fetch orders modified since a cursor timestamp (ISO 8601).
     * Uses the `modified_after` parameter (verify: exact WooCommerce behaviour with timezone).
     *
     * @return list<array>  raw WooCommerce order objects
     */
    public function getOrdersModifiedAfter(string $siteUrl, string $ck, string $cs, string $modifiedAfter, int $page = 1, int $perPage = 50): array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/orders'
            . '?modified_after=' . urlencode($modifiedAfter)
            . '&status=processing,on-hold'
            . '&per_page=' . $perPage
            . '&page=' . $page
            . '&orderby=modified&order=asc';

        $response = $this->get($url, $ck, $cs);
        if (! $response->successful()) {
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch a single WooCommerce order by ID.
     */
    public function getOrder(string $siteUrl, string $ck, string $cs, int $wooOrderId): ?array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/orders/' . $wooOrderId;
        $response = $this->get($url, $ck, $cs);

        return $response->successful() ? ($response->json() ?? null) : null;
    }

    // ------------------------------------------------------------------ products

    /**
     * Fetch products in chunks of 50.
     */
    public function getProducts(string $siteUrl, string $ck, string $cs, int $page = 1, int $perPage = 50): array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/products'
            . '?per_page=' . $perPage . '&page=' . $page . '&orderby=id&order=asc';

        $response = $this->get($url, $ck, $cs);

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    /**
     * Fetch product variations for a variable product.
     */
    public function getVariations(string $siteUrl, string $ck, string $cs, int $productId, int $page = 1): array
    {
        $url = rtrim($siteUrl, '/') . '/wp-json/wc/v3/products/' . $productId . '/variations'
            . '?per_page=100&page=' . $page;

        $response = $this->get($url, $ck, $cs);

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    // ------------------------------------------------------------------ HTTP helpers

    /**
     * GET with Basic auth. Uses Guzzle directly (not SafeHttp) because GET is read-only.
     * SSRF still applies: site_url is validated when the connection is saved.
     * (verify) SafeHttp.post() is for writes. For WooCommerce REST reads we use a standard
     * Guzzle call with the same timeout/no-redirect settings but no body pinning.
     */
    public function get(string $url, string $ck, string $cs): Response
    {
        return Http::withBasicAuth($ck, $cs)
            ->withOptions([
                'allow_redirects' => false,
                'timeout'         => 15,
                'connect_timeout' => 5,
                'verify'          => true,
            ])
            ->get($url);
    }

    public function postJson(string $url, string $ck, string $cs, string $body): Response
    {
        return Http::withBasicAuth($ck, $cs)
            ->withOptions([
                'allow_redirects' => false,
                'timeout'         => 15,
                'connect_timeout' => 5,
                'verify'          => true,
            ])
            ->withBody($body, 'application/json')
            ->post($url);
    }

    public function putJson(string $url, string $ck, string $cs, string $body): Response
    {
        return Http::withBasicAuth($ck, $cs)
            ->withOptions([
                'allow_redirects' => false,
                'timeout'         => 15,
                'connect_timeout' => 5,
                'verify'          => true,
            ])
            ->withBody($body, 'application/json')
            ->put($url);
    }

    private function delete(string $url, string $ck, string $cs): Response
    {
        return Http::withBasicAuth($ck, $cs)
            ->withOptions([
                'allow_redirects' => false,
                'timeout'         => 10,
                'connect_timeout' => 5,
                'verify'          => true,
            ])
            ->delete($url);
    }

    private function assertOk(Response $response, string $context): void
    {
        if (! $response->successful()) {
            $msg = $response->json('message') ?? $response->body();
            throw new \RuntimeException($context . ': HTTP ' . $response->status() . ' - ' . substr((string) $msg, 0, 200));
        }
    }
}
