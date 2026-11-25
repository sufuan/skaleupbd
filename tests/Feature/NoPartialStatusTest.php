<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\OrderService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner decision (2026-11-25): the 'Partial' order status is removed
 * entirely. Sellers settle an order as Delivered or Returned only.
 *
 *  - 'Partial' appears in NO selectable status list (order tabs/counts,
 *    Super Edit, orders dashboard filter) and the lifecycle choke point
 *    refuses it as a target.
 *  - A courier report of a partial delivery stays record-only: the raw
 *    text lands in courier_status, the order's own status never moves
 *    (every courier webhook funnels through BookingService, whose
 *    'partial' guard is exactly that record-only rule).
 *  - A legacy row that somehow still holds status 'Partial' must not
 *    crash any surface and must be movable out to Delivered/Cancelled.
 */
class NoPartialStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Business, User} */
    private function shopWithPoints(): array
    {
        $biz = Business::create(['name' => 'NoPartial Shop', 'public_id' => 'nopartial-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'NoPartial Owner', 'email' => 'nopartial-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'nopartial test', $owner->id);

        return [$biz, $owner];
    }

    private function makeOrder(Business $biz, User $owner, int $stock = 10, int $qty = 2): array
    {
        $product = Product::create(['business_id' => $biz->id, 'name' => 'NP Panjabi', 'sku' => 'NP-' . uniqid(), 'price' => 1300, 'cost' => 900, 'stock_qty' => $stock]);
        $order = app(OrderService::class)->create($biz->id, [
            'customer_name' => 'NP Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 1, Dhanmondi, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'price' => 1300]],
        ], $owner, 'nopartial test');

        return [$order, $product];
    }

    /** Force a status onto the row directly, the way a legacy row would exist. */
    private function forceStatus(Order $order, string $status): void
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
    }

    // ------------------------------------------------------- not selectable

    public function test_partial_is_not_offered_or_accepted_anywhere(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$order] = $this->makeOrder($biz, $owner);

        $this->assertNotContains('Partial', array_merge(Order::STATUSES, Order::EXTRA_STATUSES));

        // The lifecycle choke point refuses it as a target.
        try {
            app(OrderService::class)->changeStatus($order, 'Partial', $owner);
            $this->fail('changeStatus to Partial must be refused');
        } catch (ValidationException) {
            $this->assertSame('Pending', $order->fresh()->status);
        }

        // Super Edit: the page's status list has no Partial, and a forged
        // POST of status=Partial is refused by validation.
        $page = $this->actingAs($owner)->get('/orders/super-edit')->assertOk()->viewData('page');
        $this->assertNotContains('Partial', $page['props']['statuses']);
        $this->actingAs($owner)->post('/orders/super-edit/' . $order->id, ['status' => 'Partial'])
            ->assertSessionHasErrors('status');
        $this->assertSame('Pending', $order->fresh()->status);

        // Orders dashboard filter list has no Partial.
        $page = $this->actingAs($owner)->get('/orders/dashboard')->assertOk()->viewData('page');
        $this->assertNotContains('Partial', $page['props']['statuses']);

        // Order list counts carry no Partial bucket.
        $page = $this->actingAs($owner)->get('/orders')->assertOk()->viewData('page');
        $this->assertArrayNotHasKey('Partial', $page['props']['counts']);
    }

    // ------------------------------------------- courier partial = record-only

    public function test_partial_courier_reports_are_record_only_at_the_service_funnel(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$order] = $this->makeOrder($biz, $owner);
        app(OrderService::class)->approve($order, $owner);
        app(OrderService::class)->changeStatus($order->fresh(), 'Shipped', $owner, 'Sent out');

        $booking = app(BookingService::class);
        // The strings the drivers/webhooks actually produce for a partial
        // delivery: Steadfast/Pathao/Carrybee slug, eCourier text form.
        foreach (['partial_delivered', 'Partially Delivered'] as $text) {
            $booking->applyCourierStatus($order->fresh(), $text, $owner);
            $fresh = $order->fresh();
            $this->assertSame('Shipped', $fresh->status, "a partial report ({$text}) must never move the order");
            $this->assertSame($text, $fresh->courier_status, 'the raw courier text is still recorded');
            $this->assertNull($fresh->delivered_at);
        }

        // And the funnel is not wedged: a real delivery still delivers.
        $booking->applyCourierStatus($order->fresh(), 'delivered', $owner);
        $this->assertSame('Delivered', $order->fresh()->status);
    }

    public function test_steadfast_partial_delivered_webhook_records_status_but_never_delivers(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$order] = $this->makeOrder($biz, $owner);
        $method = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Steadfast', 'courier_type' => 'STEADFAST',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
            'credentials' => ['webhook_token' => 'sf-secret'],
        ]);
        app(OrderService::class)->approve($order, $owner);
        $order->update([
            'courier_method_id' => $method->id, 'booking_status' => 'booked',
            'tracking_id' => 'SF-CN-1', 'consignment_id' => 'SF-CN-1', 'courier_status' => 'In Transit',
        ]);
        app(OrderService::class)->changeStatus($order->fresh(), 'Shipped', $owner, 'Sent via Steadfast');

        $send = function (string $status) use ($method, $order) {
            $body = json_encode([
                'notification_type' => 'delivery_status', 'status' => $status,
                'consignment_id' => 'SF-CN-1', 'invoice' => $order->invoice_no,
            ]);
            return $this->call('POST', '/api/webhooks/steadfast/dm/' . $method->id, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer sf-secret',
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', (string) $body, 'sf-secret'),
            ], $body);
        };

        $send('partial_delivered')->assertOk();
        $fresh = $order->fresh();
        $this->assertSame('Shipped', $fresh->status, 'a partial-delivery webhook must leave the order status unchanged');
        $this->assertSame('partial_delivered', $fresh->courier_status);
        $this->assertNull($fresh->delivered_at);

        $send('delivered')->assertOk();
        $this->assertSame('Delivered', $order->fresh()->status, 'a later full delivery still lands');
    }

    // ------------------------------------------------------------ legacy rows

    public function test_legacy_partial_row_renders_and_moves_out_cleanly(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        [$order, $product] = $this->makeOrder($biz, $owner);
        app(OrderService::class)->approve($order, $owner); // Processing, 2 units reserved
        $this->forceStatus($order, 'Partial');

        // Surfaces render without crashing.
        $this->actingAs($owner)->get('/orders')->assertOk();
        $this->actingAs($owner)->get('/orders/' . $order->id)->assertOk();
        $this->actingAs($owner)->get('/orders?status=Partial')->assertOk();

        // It can be delivered: the held reservation is consumed exactly once.
        app(OrderService::class)->changeStatus($order->fresh(), 'Delivered', $owner, 'Settled as delivered');
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(8, (int) $product->fresh()->stock_qty);
        $this->assertSame(0, (int) $product->fresh()->reserved_qty);

        // A second legacy row can be cancelled out (via Super Edit, the
        // surface a support user would actually use).
        [$order2] = $this->makeOrder($biz, $owner);
        $this->forceStatus($order2, 'Partial');
        $this->actingAs($owner)->post('/orders/super-edit/' . $order2->id, ['status' => 'Cancelled'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Cancelled', $order2->fresh()->status);
    }
}
