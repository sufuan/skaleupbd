<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Business;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SmsLog;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\SettingsService;
use App\Services\SmsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clean-slate Round 1 Phase 3 fix batch (2026-11-26, from the fresh-sim
 * e-commerce cycle):
 *
 *  ITEM 1 - the connection health endpoint filtered webhook_deliveries
 *  on a `status` column and an `updated_at` column the attempt log
 *  does not have, so it threw on every load and the Connect page sat
 *  on "Could not load connection status" forever while the connection
 *  itself worked. Its pending counts also read an event status
 *  ('pending') that never occurs. All counts now use the vocabulary
 *  the tables really carry: waiting events are received/failed (the
 *  definition WooConnectController and OutboundWebhookService use),
 *  and failed deliveries are outcome='failed' by created_at.
 *
 *  ITEM 2 - the external API controller hard-coded the line name
 *  'Item' whenever the caller omitted it, defeating OrderService's
 *  own fallback (name ?? product name ?? 'Item'): a linked Plain Cap
 *  read "Item 2x 250" on the order. The omitted/blank name now
 *  reaches OrderService as null.
 *
 *  ITEM 3 - an external API order that omitted deliveryCharge fell
 *  back to the shop's inside-Dhaka figure even for an outside-Dhaka
 *  address (a Chattogram order was charged 60, not 120). The omitted
 *  case now applies the same zone split as the chat-order and
 *  storefront paths; an explicitly sent charge is still respected.
 *
 *  ITEM 4 (investigated - no defect) - on a fresh shop the order SMS
 *  lands in sms_logs as a clean failed row ('Not enough SMS
 *  balance'): SmsService::send records every refusal as a log row
 *  and never throws, while the platform OTP (sendPlatform) bypasses
 *  the shop's marketing balance by design and succeeds. The clean
 *  failure is locked in here as the expected behaviour.
 */
class Phase3FixBatch2Test extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    /** @return array{Business, User} */
    private function shopWithPoints(): array
    {
        $biz = Business::create(['name' => 'Phase3b Shop', 'public_id' => 'phase3b-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Phase3b Owner', 'email' => 'phase3b-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'phase3 fix batch 2 test', $owner->id);

        return [$biz, $owner];
    }

    private function makeKey(int $businessId): string
    {
        $raw = 'sk_' . str_repeat('a', 48) . $businessId;
        ApiKey::create([
            'business_id' => $businessId,
            'key_hash' => hash('sha256', $raw),
            'last4' => substr($raw, -4),
            'scopes' => ['orders.write'],
        ]);

        return $raw;
    }

    /** POST one order through the real external API; returns the decoded envelope. */
    private function postApiOrder(Business $biz, string $rawKey, array $payload): array
    {
        $response = $this->withHeaders(['x-api-key' => $rawKey, 'businessId' => $biz->public_id])
            ->postJson('/api/external/v1/orders', $payload);
        $response->assertStatus(201);

        return $response->json();
    }

    private function apiOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'customerName' => 'API Customer',
            'customerNumber' => '01712345678',
            'customerAddress' => 'House 5, Agrabad, Chattogram',
            'district' => 'Chattogram',
            'deliveryCharge' => 60,
            'products' => [['quantity' => 1, 'price' => 100]],
        ], $overrides);
    }

    // ------------------------------------------------- ITEM 1: health endpoint

    public function test_connection_health_resolves_with_deliveries_in_every_real_state(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $conn = IntegrationConnection::create([
            'business_id' => $biz->id,
            'platform' => 'woo',
            'site_url' => 'https://woosim.example',
            'status' => 'active',
            'credentials_enc' => ['ck' => 'ck_test', 'cs' => 'cs_test'],
            'webhook_secret_enc' => 'whsec_test1234',
            'webhook_secret_last4' => '1234',
            'public_token' => 'tok_' . str_repeat('b', 44),
            'failure_count' => 0,
        ]);

        $mkEvent = fn (string $direction, string $status) => IntegrationEvent::create([
            'connection_id' => $conn->id, 'direction' => $direction, 'topic' => 'order.created',
            'event_uuid' => (string) Str::uuid(), 'dedupe_key' => 'dk-' . Str::random(16),
            'payload' => '{}', 'status' => $status, 'attempts' => 0,
        ]);

        // Inbound: two waiting (received, failed), one done.
        $mkEvent('in', 'received');
        $mkEvent('in', 'failed');
        $mkEvent('in', 'processed');
        // Outbound: two waiting (received, failed), one done, one dead.
        $mkEvent('out', 'received');
        $failedOut = $mkEvent('out', 'failed');
        $mkEvent('out', 'processed');
        $mkEvent('out', 'dead');

        $mkDelivery = function (string $outcome, ?string $createdAt = null) use ($conn, $failedOut) {
            $row = WebhookDelivery::create([
                'connection_id' => $conn->id, 'event_id' => $failedOut->id, 'topic' => 'order.updated',
                'attempt' => 1, 'outcome' => $outcome, 'http_status' => $outcome === 'delivered' ? 200 : 500,
                'duration_ms' => 42, 'next_retry_at' => $outcome === 'failed' ? now()->addMinutes(5) : null,
            ]);
            if ($createdAt !== null) {
                DB::table('webhook_deliveries')->where('id', $row->id)->update(['created_at' => $createdAt]);
            }

            return $row;
        };

        // Attempt log: two failures inside 24 h, one older failure, one
        // delivered, one blocked (blocked is not a "failed delivery").
        $mkDelivery('failed');
        $mkDelivery('failed');
        $mkDelivery('failed', now()->subDays(2)->toDateTimeString());
        $mkDelivery('delivered');
        $mkDelivery('blocked');

        $response = $this->actingAs($owner)
            ->getJson("/storefront/integrations/connections/{$conn->id}/health");

        $response->assertOk()
            ->assertJsonPath('connection_id', $conn->id)
            ->assertJsonPath('status_label', 'active')
            ->assertJsonPath('pending_inbound', 2)
            ->assertJsonPath('pending_outbound', 2)
            ->assertJsonPath('recent_failures', 2);
    }

    public function test_connection_health_is_scoped_to_the_owning_shop(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$otherBiz] = $this->shopWithPoints();
        $foreign = IntegrationConnection::create([
            'business_id' => $otherBiz->id,
            'platform' => 'woo',
            'site_url' => 'https://other.example',
            'status' => 'active',
            'credentials_enc' => ['ck' => 'ck_test', 'cs' => 'cs_test'],
            'webhook_secret_enc' => 'whsec_test1234',
            'webhook_secret_last4' => '1234',
            'public_token' => 'tok_' . str_repeat('c', 44),
            'failure_count' => 0,
        ]);

        $this->actingAs($owner)
            ->getJson("/storefront/integrations/connections/{$foreign->id}/health")
            ->assertNotFound();
    }

    // ------------------------------------------- ITEM 2: API line names

    public function test_api_order_line_without_a_name_uses_the_product_name(): void
    {
        [$biz] = $this->shopWithPoints();
        $raw = $this->makeKey($biz->id);
        $cap = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'P3B-CAP', 'price' => 250, 'stock_qty' => 40]);

        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload([
            'products' => [['productId' => $cap->id, 'quantity' => 2, 'price' => 250]],
        ]));

        $item = Order::findOrFail($envelope['data']['orderId'])->items->sole();
        $this->assertSame('Plain Cap', $item->name);
        $this->assertSame(2, (int) $item->qty);
    }

    public function test_api_order_variant_line_resolves_product_and_variant_names(): void
    {
        [$biz] = $this->shopWithPoints();
        $raw = $this->makeKey($biz->id);
        $shirt = Product::create(['business_id' => $biz->id, 'name' => 'Premium T-Shirt', 'sku' => 'P3B-TEE', 'price' => 850, 'stock_qty' => 0]);
        $large = ProductVariant::create([
            'business_id' => $biz->id, 'product_id' => $shirt->id,
            'name' => 'L', 'price' => 850, 'stock_qty' => 7,
        ]);

        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload([
            'products' => [['productId' => $shirt->id, 'variantId' => $large->id, 'quantity' => 1, 'price' => 850]],
        ]));

        $item = Order::findOrFail($envelope['data']['orderId'])->items->sole();
        $this->assertSame('Premium T-Shirt', $item->name);
        $this->assertSame('L', $item->variant_name);
    }

    public function test_api_order_line_keeps_a_caller_supplied_name(): void
    {
        [$biz] = $this->shopWithPoints();
        $raw = $this->makeKey($biz->id);
        $cap = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'P3B-CAP2', 'price' => 250, 'stock_qty' => 40]);

        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload([
            'products' => [['productId' => $cap->id, 'name' => 'Gift Box Cap', 'quantity' => 1, 'price' => 250]],
        ]));

        $item = Order::findOrFail($envelope['data']['orderId'])->items->sole();
        $this->assertSame('Gift Box Cap', $item->name);
    }

    public function test_api_free_text_line_without_a_name_stays_item(): void
    {
        [$biz] = $this->shopWithPoints();
        $raw = $this->makeKey($biz->id);

        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload());

        $item = Order::findOrFail($envelope['data']['orderId'])->items->sole();
        $this->assertSame('Item', $item->name);
    }

    // ------------------------------------------- ITEM 3: API zone charge

    private function shopWithZoneCharges(): Business
    {
        [$biz] = $this->shopWithPoints();
        $settings = app(SettingsService::class);
        $settings->set("business.{$biz->id}.default_delivery_cost", 70.0);
        $settings->set("business.{$biz->id}.default_delivery_cost_outside", 130.0);

        return $biz;
    }

    public function test_api_order_omitting_the_charge_pays_the_inside_rate_for_a_dhaka_district(): void
    {
        $biz = $this->shopWithZoneCharges();
        $raw = $this->makeKey($biz->id);

        $payload = $this->apiOrderPayload(['district' => 'Dhaka', 'customerAddress' => 'House 1, Dhanmondi, Dhaka']);
        unset($payload['deliveryCharge']);
        $envelope = $this->postApiOrder($biz, $raw, $payload);

        $this->assertSame(70.0, (float) Order::findOrFail($envelope['data']['orderId'])->delivery_charge);
    }

    public function test_api_order_omitting_the_charge_pays_the_outside_rate_for_an_outside_district(): void
    {
        $biz = $this->shopWithZoneCharges();
        $raw = $this->makeKey($biz->id);

        $payload = $this->apiOrderPayload(); // district Chattogram
        unset($payload['deliveryCharge']);
        $envelope = $this->postApiOrder($biz, $raw, $payload);

        $this->assertSame(130.0, (float) Order::findOrFail($envelope['data']['orderId'])->delivery_charge);
    }

    public function test_api_order_omitting_the_charge_reads_the_zone_from_the_address_when_no_district_is_sent(): void
    {
        $biz = $this->shopWithZoneCharges();
        $raw = $this->makeKey($biz->id);

        $payload = $this->apiOrderPayload();
        unset($payload['deliveryCharge'], $payload['district']);
        $envelope = $this->postApiOrder($biz, $raw, $payload); // address names Chattogram

        $this->assertSame(130.0, (float) Order::findOrFail($envelope['data']['orderId'])->delivery_charge);
    }

    public function test_api_order_explicit_charge_is_respected_even_outside_dhaka(): void
    {
        $biz = $this->shopWithZoneCharges();
        $raw = $this->makeKey($biz->id);

        // Explicit 60 to Chattogram (the sim run's figure, when sent).
        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload(['deliveryCharge' => 60]));
        $this->assertSame(60.0, (float) Order::findOrFail($envelope['data']['orderId'])->delivery_charge);

        // Explicit free delivery stays free.
        $envelope = $this->postApiOrder($biz, $raw, $this->apiOrderPayload(['deliveryCharge' => 0]));
        $this->assertSame(0.0, (float) Order::findOrFail($envelope['data']['orderId'])->delivery_charge);
    }

    // ------------------------------------------- ITEM 4: fresh-shop SMS

    public function test_fresh_shop_order_sms_is_a_clean_logged_failure_not_an_exception(): void
    {
        $biz = Business::create(['name' => 'Phase3b Fresh', 'public_id' => 'phase3b-fresh-' . uniqid(), 'status' => 'approved']);

        // No SMS balance, no gateway key: the send must come back as a
        // recorded refusal, exactly as the sim's sms_logs rows showed.
        $log = app(SmsService::class)->send($biz->id, '01712345678', 'Your order has shipped', [
            'type' => 'automation', 'key' => 'shipped', 'label' => 'Order shipped', 'ref' => 'OB999',
        ]);

        $this->assertSame('failed', $log->status);
        $this->assertSame('Not enough SMS balance', $log->error);
        $this->assertSame(1, SmsLog::where('business_id', $biz->id)->count());
    }
}
