<?php

// Goes in: tests/Feature/WooWebhookTest.php (new file).
// Tests inbound WooCommerce webhook security and processing.

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use App\Services\Integrations\WooCommerceHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WooWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeConnection(string $status = 'active', ?string $token = null): IntegrationConnection
    {
        $secret = 'whsec_test1234';
        $conn   = IntegrationConnection::create([
            'business_id'          => 1,
            'platform'             => 'woo',
            'site_url'             => 'https://testshop.example',
            'status'               => $status,
            'credentials_enc'      => ['ck' => 'ck_test', 'cs' => 'cs_test'],
            'webhook_secret_enc'   => $secret,
            'webhook_secret_last4' => substr($secret, -4),
            'public_token'         => $token ?? 'tok_' . str_repeat('a', 44),
            'failure_count'        => 0,
        ]);

        return $conn;
    }

    private function webhookHeaders(string $body, IntegrationConnection $conn): array
    {
        return [
            'X-WC-Webhook-Signature' => WooCommerceHmac::sign((string) $conn->webhook_secret_enc, $body),
            'X-WC-Webhook-ID'        => '99901',
        ];
    }

    // ------------------------------------------------------------------ happy path

    public function test_valid_order_webhook_stores_event_and_returns_200(): void
    {
        Queue::fake();
        $conn = $this->makeConnection();
        $body = json_encode(['id' => 42, 'status' => 'processing', 'billing' => ['phone' => '01700000001']]);

        $this->withHeaders($this->webhookHeaders($body, $conn))
            ->postJson('/api/webhooks/woo/' . $conn->public_token . '/order/created', json_decode($body, true))
            ->assertStatus(200);

        $this->assertDatabaseHas('integration_events', [
            'connection_id' => $conn->id,
            'topic'         => 'order.created',
            'status'        => 'received',
        ]);

        Queue::assertPushed(\App\Jobs\Integrations\ProcessWooWebhookJob::class);
    }

    // ------------------------------------------------------------------ duplicate delivery (idempotency)

    public function test_duplicate_wc_delivery_id_does_not_create_second_event(): void
    {
        Queue::fake();
        $conn    = $this->makeConnection();
        $body    = json_encode(['id' => 55, 'status' => 'processing']);
        $headers = $this->webhookHeaders($body, $conn);

        $this->withHeaders($headers)->postJson('/api/webhooks/woo/' . $conn->public_token . '/order/created', json_decode($body, true))->assertStatus(200);
        $this->withHeaders($headers)->postJson('/api/webhooks/woo/' . $conn->public_token . '/order/created', json_decode($body, true))->assertStatus(200);

        $this->assertSame(1, \DB::table('integration_events')->where('connection_id', $conn->id)->where('topic', 'order.created')->count());
    }

    // ------------------------------------------------------------------ bad HMAC

    public function test_bad_hmac_is_rejected_silently(): void
    {
        Queue::fake();
        $conn = $this->makeConnection();
        $body = json_encode(['id' => 77, 'status' => 'processing']);

        $this->withHeaders([
                'X-WC-Webhook-Signature' => base64_encode('wrong'),
                'X-WC-Webhook-ID'        => '12345',
            ])
            ->postJson('/api/webhooks/woo/' . $conn->public_token . '/order/created', json_decode($body, true))
            ->assertStatus(200); // 200 so WC does not auto-disable

        // But no event stored.
        $this->assertDatabaseMissing('integration_events', ['connection_id' => $conn->id]);

        Queue::assertNotPushed(\App\Jobs\Integrations\ProcessWooWebhookJob::class);
    }

    // ------------------------------------------------------------------ unknown token

    public function test_unknown_token_returns_200_and_nothing_stored(): void
    {
        Queue::fake();
        $body = json_encode(['id' => 1]);

        $this->withHeaders(['X-WC-Webhook-Signature' => 'x', 'X-WC-Webhook-ID' => '1'])
            ->postJson('/api/webhooks/woo/NONEXISTENT_TOKEN/order/created', json_decode($body, true))
            ->assertStatus(200);

        $this->assertDatabaseCount('integration_events', 0);
    }

    // ------------------------------------------------------------------ cross-tenant isolation

    public function test_token_from_shop_a_cannot_import_to_shop_b(): void
    {
        Queue::fake();

        // Two shops, two connections, different tokens.
        $connA = $this->makeConnection(token: 'tok_' . str_repeat('a', 44));
        IntegrationConnection::create([
            'business_id'          => 2,
            'platform'             => 'woo',
            'site_url'             => 'https://shopb.example',
            'status'               => 'active',
            'credentials_enc'      => ['ck' => 'ck_b', 'cs' => 'cs_b'],
            'webhook_secret_enc'   => 'whsec_shopb',
            'webhook_secret_last4' => 'hopb',
            'public_token'         => 'tok_' . str_repeat('b', 44),
            'failure_count'        => 0,
        ]);

        // Using shop A's token with shop A's signature: only shop A's connection is found.
        $body = json_encode(['id' => 100, 'status' => 'processing']);
        $this->withHeaders($this->webhookHeaders($body, $connA))
            ->postJson('/api/webhooks/woo/' . $connA->public_token . '/order/created', json_decode($body, true))
            ->assertStatus(200);

        // Event belongs to shop A's connection only.
        $this->assertSame(1, \DB::table('integration_events')
            ->where('connection_id', $connA->id)->count());

        // Shop B's connection has no events.
        $connB = IntegrationConnection::where('business_id', 2)->first();
        $this->assertSame(0, \DB::table('integration_events')
            ->where('connection_id', $connB->id)->count());
    }

    // ------------------------------------------------------------------ zero points: event stored, job exits early

    public function test_at_zero_points_event_stored_but_marked_paused(): void
    {
        Queue::fake();

        // Bind a mock IntegrationHost that returns 0 points.
        $this->app->bind(\App\Services\Integrations\IntegrationHost::class, function () {
            $mock = new class extends \App\Services\Integrations\IntegrationHost {
                public function pointsAvailable(int $businessId): int { return 0; }
            };
            return $mock;
        });

        $conn = $this->makeConnection();
        $body = json_encode(['id' => 200, 'status' => 'processing']);
        $this->withHeaders($this->webhookHeaders($body, $conn))
            ->postJson('/api/webhooks/woo/' . $conn->public_token . '/order/created', json_decode($body, true))
            ->assertStatus(200);

        // Event is stored.
        $this->assertDatabaseHas('integration_events', [
            'connection_id' => $conn->id,
            'status'        => 'received',  // not yet processed
        ]);

        // The job runs but exits early (paused). We test the job directly.
        $event = \App\Models\IntegrationEvent::where('connection_id', $conn->id)->firstOrFail();
        $job   = new \App\Jobs\Integrations\ProcessWooWebhookJob($event->id);
        $job->handle(
            app(\App\Services\Integrations\IntegrationHost::class),
            app(\App\Services\Integrations\WooCommerceOrderImporter::class),
        );

        $event->refresh();
        $this->assertSame('paused_no_points', $event->error);
        $this->assertSame('received', $event->status); // not moved to processed
    }
}
