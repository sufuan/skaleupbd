<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CourierMethod;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\Integrations\WooCommerceHmac;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 1 Phase 3 fix batch (2026-11-25, from the sim e-commerce cycle):
 *
 *  DEFECT 1 (critical) - WooWebhookController deduped on the
 *  X-WC-Webhook-ID header, which is the webhook's CONFIG id (1/2/4 per
 *  topic), not a per-delivery id. integration_events.event_uuid is
 *  UNIQUE, so the first delivery of each topic claimed that small
 *  integer and every later delivery of the same webhook collided and
 *  was silently discarded with HTTP 200: Woo order #17 never imported.
 *  Dedupe now keys on a hash of the full raw payload.
 *
 *  DEFECT 2 - a Manual-courier order could be booked and Shipped but
 *  never marked Delivered anywhere. Scan To Update gains a Delivery
 *  mode for couriers with no status feed (manual / mock) only.
 *
 *  CHECK ITEM - the Woo importer read district as
 *  ($billing['state'] ?? $billing['city']): Woo sends "state" as an
 *  empty string when no states are configured, so the city was thrown
 *  away and a Dhaka order's zone (modal charge AND committed charge)
 *  fell to Outside Dhaka. First non-empty of state/city now wins.
 */
class Phase3FixTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    /** @return array{Business, User} */
    private function shopWithPoints(): array
    {
        $biz = Business::create(['name' => 'Phase3 Shop', 'public_id' => 'phase3-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Phase3 Owner', 'email' => 'phase3-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'phase3fix test', $owner->id);

        return [$biz, $owner];
    }

    private function balance(int $bizId): int
    {
        return (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
    }

    private function makeConnection(int $bizId): IntegrationConnection
    {
        return IntegrationConnection::create([
            'business_id' => $bizId,
            'platform' => 'woo',
            'site_url' => 'https://woosim.example',
            'status' => 'active',
            'credentials_enc' => ['ck' => 'ck_test', 'cs' => 'cs_test'],
            'webhook_secret_enc' => 'whsec_test1234',
            'webhook_secret_last4' => '1234',
            'public_token' => 'tok_' . str_repeat('a', 44),
            'failure_count' => 0,
        ]);
    }

    private function wooPayload(int $wooId, string $status = 'processing', array $billing = []): array
    {
        return [
            'id' => $wooId,
            'status' => $status,
            'billing' => array_merge([
                'first_name' => 'Woo', 'last_name' => 'Customer',
                'phone' => '01798765432',
                'address_1' => 'House 10, Road 5, Dhanmondi',
                'address_2' => '',
                'city' => 'Dhaka',
                'state' => '',
            ], $billing),
            'line_items' => [
                ['product_id' => 900 + $wooId, 'variation_id' => 0, 'quantity' => 1, 'subtotal' => '1300.00', 'sku' => '', 'name' => 'Woo Panjabi'],
            ],
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'customer_note' => '',
        ];
    }

    /** POST one delivery exactly as Woo sends it: same config-id header on every delivery. */
    private function deliver(IntegrationConnection $conn, string $topicPath, array $payload, string $webhookConfigId = '1'): void
    {
        $body = json_encode($payload);
        $this->call('POST', '/api/webhooks/woo/' . $conn->public_token . '/' . $topicPath, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => WooCommerceHmac::sign((string) $conn->webhook_secret_enc, $body),
            'HTTP_X_WC_WEBHOOK_ID' => $webhookConfigId,
        ], $body)->assertStatus(200);
    }

    // ------------------------------------------------- DEFECT 1: woo dedupe

    public function test_two_different_orders_from_the_same_webhook_both_import(): void
    {
        [$biz] = $this->shopWithPoints();
        $conn = $this->makeConnection($biz->id);
        $before = $this->balance($biz->id);

        // Both deliveries carry the SAME X-WC-Webhook-ID (the config id
        // Woo reuses for every delivery of that webhook) - the exact
        // shape that used to kill order #17 on the sim.
        $this->deliver($conn, 'order/created', $this->wooPayload(16), '1');
        $this->deliver($conn, 'order/created', $this->wooPayload(17), '1');

        $orders = Order::where('business_id', $biz->id)->orderBy('id')->get();
        $this->assertCount(2, $orders, 'both Woo orders must import');
        $this->assertSame(['woo:16', 'woo:17'], $orders->pluck('external_ref')->all());
        $this->assertSame($before - 2, $this->balance($biz->id), 'exactly one point per imported order');

        $events = DB::table('integration_events')->where('connection_id', $conn->id)->get();
        $this->assertCount(2, $events);
        $this->assertSame(['processed', 'processed'], $events->pluck('status')->all());
        $this->assertCount(2, $events->pluck('event_uuid')->unique(), 'each stored event carries its own uuid');
        $this->assertCount(2, $events->pluck('dedupe_key')->unique(), 'each delivery has its own dedupe key');
    }

    public function test_identical_redelivery_does_not_double_import_or_double_deduct(): void
    {
        [$biz] = $this->shopWithPoints();
        $conn = $this->makeConnection($biz->id);
        $before = $this->balance($biz->id);

        $this->deliver($conn, 'order/created', $this->wooPayload(21), '2');
        $this->deliver($conn, 'order/created', $this->wooPayload(21), '2');

        $this->assertSame(1, DB::table('integration_events')->where('connection_id', $conn->id)->count(), 'a true duplicate stores no second event');
        $this->assertSame(1, Order::where('business_id', $biz->id)->count(), 'no second order');
        $this->assertSame($before - 1, $this->balance($biz->id), 'no second point');
    }

    public function test_changed_payload_update_for_same_order_processes_and_upserts(): void
    {
        [$biz] = $this->shopWithPoints();
        $conn = $this->makeConnection($biz->id);
        $before = $this->balance($biz->id);

        $this->deliver($conn, 'order/created', $this->wooPayload(31, 'processing'), '1');
        // A later order.updated with a CHANGED payload (here the mapped
        // status moved to on-hold): not a duplicate, must be stored and
        // processed, and must not create a second order or burn a point.
        $this->deliver($conn, 'order/updated', $this->wooPayload(31, 'on-hold'), '2');

        $events = DB::table('integration_events')->where('connection_id', $conn->id)->orderBy('id')->get();
        $this->assertCount(2, $events, 'the changed delivery is a new event, not a dedupe casualty');
        $this->assertSame(['processed', 'processed'], $events->pluck('status')->all());
        $this->assertSame($events[0]->subject_id, $events[1]->subject_id, 'both events resolve to the same order');
        $this->assertSame(1, Order::where('business_id', $biz->id)->count());
        $this->assertSame($before - 1, $this->balance($biz->id), 'the update burns no second point');
    }

    // ------------------------------------------- CHECK ITEM: woo district

    public function test_woo_order_with_empty_state_keeps_its_city_as_district(): void
    {
        [$biz] = $this->shopWithPoints();
        $conn = $this->makeConnection($biz->id);

        $this->deliver($conn, 'order/created', $this->wooPayload(41), '1');

        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame('Dhaka', $order->district, 'empty state must fall back to the city');

        $method = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Manual', 'courier_type' => 'OTHERS',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $this->assertSame(60.0, $method->chargeFor($order->effectiveDistrict()), 'a Dhaka order is charged the inside-Dhaka rate');
        $this->assertSame(60.0, app(SettlementService::class)->committedCharge(
            $order->fresh()->setRelation('courierMethod', $method)
        ), 'the committed charge follows the same zone');
    }

    public function test_woo_order_with_a_state_uses_the_state(): void
    {
        [$biz] = $this->shopWithPoints();
        $conn = $this->makeConnection($biz->id);

        $this->deliver($conn, 'order/created', $this->wooPayload(42, 'processing', ['state' => 'Chattogram', 'city' => 'Chattogram']), '1');

        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame('Chattogram', $order->district);
    }

    // ------------------------------------- DEFECT 2: scan-to-delivery mode

    /** @return array{Order, CourierMethod, Product} */
    private function manualShippedOrder(Business $biz, User $owner): array
    {
        $product = Product::create(['business_id' => $biz->id, 'name' => 'Scan Panjabi', 'sku' => 'SCAN-1', 'price' => 1300, 'cost' => 900, 'stock_qty' => 10]);
        $method = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Manual', 'courier_type' => 'OTHERS',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $order = app(OrderService::class)->create($biz->id, [
            'customer_name' => 'Scan Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 1, Dhanmondi, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'price' => 1300]],
        ], $owner, 'phase3fix test');
        app(OrderService::class)->approve($order, $owner);

        $result = app(BookingService::class)->send($order->fresh(), $method->id, 'CN-MANUAL-1', null, $owner);
        $this->assertTrue($result['ok'], 'manual booking must succeed: ' . ($result['error'] ?? ''));
        $this->assertSame('Shipped', $order->fresh()->status);

        return [$order->fresh(), $method, $product];
    }

    public function test_manual_courier_order_completes_via_scan_delivery(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$order, $method, $product] = $this->manualShippedOrder($biz, $owner);

        // After booking: stock consumed once (at ship), nothing reserved.
        $this->assertSame(8, (int) $product->fresh()->stock_qty);
        $this->assertSame(0, (int) $product->fresh()->reserved_qty);

        $response = $this->actingAs($owner)->postJson('/delivery/scan', ['code' => 'CN-MANUAL-1', 'type' => 'delivery']);
        $response->assertOk()->assertJson(['ok' => true, 'status' => 'Delivered']);

        $order = $order->fresh();
        $this->assertSame('Delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('Delivered', $order->courier_status);

        // Stock consumed exactly once across the whole journey.
        $this->assertSame(8, (int) $product->fresh()->stock_qty, 'delivery must not consume stock a second time');
        $this->assertSame(0, (int) $product->fresh()->reserved_qty);
        $shipOut = (int) DB::table('stock_ledger')->where('order_id', $order->id)->where('type', 'ship_out')->sum('qty');
        $this->assertSame(2, $shipOut, 'exactly one ship-out of the ordered quantity');

        // The COD is recognised: the delivered total lands in revenue,
        // and the parcel's COD shows as collected on the courier ledger.
        $figures = app(FinanceService::class)->figures($biz->id, now()->startOfDay(), now()->endOfDay());
        $this->assertSame((float) $order->grand_total, $figures['revenue']);
        $totals = app(SettlementService::class)->courierTotals($biz->id, $method->id);
        $this->assertSame($order->codAmount(), $totals['collected']);

        $this->assertDatabaseHas('scan_events', [
            'business_id' => $biz->id, 'order_id' => $order->id,
            'scan_type' => 'delivery', 'result' => 'success',
        ]);

        // A second delivery scan is a duplicate, never a second completion.
        $this->actingAs($owner)->postJson('/delivery/scan', ['code' => 'CN-MANUAL-1', 'type' => 'delivery'])
            ->assertOk()->assertJson(['ok' => false, 'duplicate' => true]);
        $this->assertSame(8, (int) $product->fresh()->stock_qty);
    }

    public function test_api_courier_order_cannot_be_completed_via_scan_delivery(): void
    {
        config(['couriers.mock' => false]); // the real driver resolves: Pathao reports delivery itself
        [$biz, $owner] = $this->shopWithPoints();
        $product = Product::create(['business_id' => $biz->id, 'name' => 'Api Panjabi', 'sku' => 'SCAN-2', 'price' => 850, 'cost' => 500, 'stock_qty' => 5]);
        $method = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Pathao', 'courier_type' => 'PATHAO',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $order = app(OrderService::class)->create($biz->id, [
            'customer_name' => 'Api Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 2, Uttara, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 850]],
        ], $owner, 'phase3fix test');
        app(OrderService::class)->approve($order, $owner);
        $order->update([
            'courier_method_id' => $method->id, 'booking_status' => 'booked',
            'tracking_id' => 'CN-API-1', 'consignment_id' => 'CN-API-1', 'courier_status' => 'Booked',
        ]);
        app(OrderService::class)->changeStatus($order->fresh(), 'Shipped', $owner, 'Sent via Pathao');

        $this->actingAs($owner)->postJson('/delivery/scan', ['code' => 'CN-API-1', 'type' => 'delivery'])
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame('Shipped', $order->fresh()->status, 'an API-courier parcel stays webhook-driven');
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_scan_delivery_rejects_a_parcel_that_has_not_shipped(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $method = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Manual', 'courier_type' => 'OTHERS',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $order = app(OrderService::class)->create($biz->id, [
            'customer_name' => 'Early Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 3, Mirpur, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['name' => 'Ad hoc item', 'qty' => 1, 'price' => 500]],
        ], $owner, 'phase3fix test');
        $order->update(['courier_method_id' => $method->id, 'tracking_id' => 'CN-EARLY-1']);

        $this->actingAs($owner)->postJson('/delivery/scan', ['code' => 'CN-EARLY-1', 'type' => 'delivery'])
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame('Pending', $order->fresh()->status);
    }
}
