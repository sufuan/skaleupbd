<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CourierMethod;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Round 2 UI/UX fix batch FOLLOW-UPS (2026-11-27) — the three gaps
 * the live re-check found in the batch itself.
 *
 *  (7b)  The Process Order panel's Delivery Method select sat on
 *        "-" for courier-CONNECTION bookings (live OB301): the
 *        booking records the method row + CN on the order, but the
 *        select can only hold a name from the shop's own list, and
 *        the stored free-text either was never written or no longer
 *        matches the list's wording. Order::
 *        deliveryMethodPresentation() derives the truthful value.
 *  (23b) Settings → Website Integration keyed its WooCommerce row
 *        to the shop's External API key alone, so a shop with a live
 *        Woo connection (integration_connections row, webhooks
 *        arriving) and no API key was told "Not connected". The
 *        settings payload now carries the connection's own summary.
 */
class Round2FollowUpTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Business, User} */
    private function shopWithPoints(): array
    {
        $biz = Business::create(['name' => 'FollowUp Shop', 'public_id' => 'followup-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'FollowUp Owner', 'email' => 'followup-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'followup test', $owner->id);

        return [$biz, $owner];
    }

    private function makeOrder(Business $biz, User $owner): Order
    {
        $product = Product::create(['business_id' => $biz->id, 'name' => 'FU Panjabi', 'sku' => 'FU-' . uniqid(), 'price' => 1300, 'cost' => 900, 'stock_qty' => 10]);

        return app(OrderService::class)->create($biz->id, [
            'customer_name' => 'FU Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 1, Dhanmondi, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 1300]],
        ], $owner, 'followup test');
    }

    private function pathaoMethod(Business $biz): CourierMethod
    {
        return CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Pathao', 'courier_type' => 'PATHAO',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
            'credentials' => ['client_id' => 'x', 'client_secret' => 'y', 'client_email' => 'a@b.c', 'password' => 'z', 'store_id' => '539'],
        ]);
    }

    // ------------------------------------------------------------ (7b)

    public function test_connection_booking_without_stored_text_preselects_the_zone_name(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $method = $this->pathaoMethod($biz);
        $order = $this->makeOrder($biz, $owner);
        // The OB301 shape: shipped through the courier connection —
        // method row + CN recorded, delivery_method never written.
        $order->update([
            'status' => 'Shipped', 'courier_method_id' => $method->id,
            'consignment_id' => 'DS261126F9NZVC', 'tracking_id' => 'DS261126F9NZVC',
            'courier_status' => 'Booked', 'delivery_method' => null,
        ]);

        $p = $order->fresh()->deliveryMethodPresentation(['Pathao - Inside Dhaka', 'Pathao - Outside Dhaka']);
        $this->assertSame('Pathao - Inside Dhaka', $p['select'], 'the select must hold the booked method zone name, not "-"');
        $this->assertNull($p['booked_with']);
    }

    public function test_stored_text_matches_the_list_despite_dash_drift(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $order = $this->makeOrder($biz, $owner);
        // BookingService writes "Pathao - Inside Dhaka" (hyphen); the
        // shop's saved list carries the same names with em-dashes.
        $order->update(['delivery_method' => 'Pathao - Inside Dhaka']);

        $p = $order->fresh()->deliveryMethodPresentation(['Pathao — Inside Dhaka', 'Pathao — Outside Dhaka']);
        $this->assertSame('Pathao — Inside Dhaka', $p['select'], 'the select must hold the list name, not "-"');
        $this->assertNull($p['booked_with']);
    }

    public function test_manual_method_booking_preselects_the_bare_label(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $manual = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Own Delivery', 'courier_type' => 'OTHERS',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $order = $this->makeOrder($biz, $owner);
        $order->update(['courier_method_id' => $manual->id, 'delivery_method' => null, 'consignment_id' => 'CN-1']);

        $p = $order->fresh()->deliveryMethodPresentation(['Own Delivery']);
        $this->assertSame('Own Delivery', $p['select']);
        $this->assertNull($p['booked_with']);
    }

    public function test_booking_with_no_matching_list_name_shows_the_recorded_courier(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $method = $this->pathaoMethod($biz);
        $order = $this->makeOrder($biz, $owner);
        $order->update([
            'status' => 'Shipped', 'courier_method_id' => $method->id,
            'consignment_id' => 'DS261126F9NZVC', 'delivery_method' => null,
        ]);

        // The shop's list names a different courier entirely: nothing
        // may be preselected, but the recorded courier + CN must be
        // presented instead of a bare "-".
        $p = $order->fresh()->deliveryMethodPresentation(['Steadfast - Inside Dhaka', 'Steadfast - Outside Dhaka']);
        $this->assertSame('', $p['select']);
        $this->assertSame('Pathao · CN DS261126F9NZVC', $p['booked_with']);
    }

    public function test_plain_order_has_no_booking_presentation(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $order = $this->makeOrder($biz, $owner);

        $p = $order->fresh()->deliveryMethodPresentation(['Pathao - Inside Dhaka']);
        $this->assertSame('', $p['select']);
        $this->assertNull($p['booked_with'], 'no booking, nothing to present');
    }

    public function test_order_page_presents_a_connection_booking_not_a_dash(): void
    {
        Queue::fake();
        [$biz, $owner] = $this->shopWithPoints();
        $method = $this->pathaoMethod($biz);
        $order = $this->makeOrder($biz, $owner);
        $order->update([
            'status' => 'Shipped', 'courier_method_id' => $method->id,
            'consignment_id' => 'DS261126F9NZVC', 'tracking_id' => 'DS261126F9NZVC',
            'courier_status' => 'Booked', 'delivery_method' => null,
        ]);

        // No saved name list in a fresh shop: the list derives from
        // the active Pathao method, so the page must preselect its
        // Inside Dhaka name for this Dhaka order.
        $response = $this->actingAs($owner)->get('/orders/' . $order->id);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('order.delivery_method_select', 'Pathao - Inside Dhaka')
            ->where('order.booked_courier', null));
    }

    // ----------------------------------------------------------- (23b)

    public function test_settings_payload_carries_the_woo_connection_not_only_the_api_key(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        // A live Woo connection and NO External API key: the old
        // payload let the Website tab call this "Not connected".
        IntegrationConnection::create([
            'business_id' => $biz->id, 'platform' => 'woo',
            'site_url' => 'https://testshop.example.com', 'status' => 'active',
            'last_event_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($owner)->get('/settings?group=api');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('woo.status', 'active')
            ->where('woo.site_url', 'https://testshop.example.com')
            ->where('integration.api_key', null));
    }

    public function test_settings_payload_reports_a_disabled_woo_connection_as_disabled(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        IntegrationConnection::create([
            'business_id' => $biz->id, 'platform' => 'woo',
            'site_url' => 'https://testshop.example.com', 'status' => 'disabled',
        ]);

        $response = $this->actingAs($owner)->get('/settings?group=api');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('woo.status', 'disabled'));
    }

    public function test_settings_payload_has_no_woo_summary_without_a_connection(): void
    {
        [$biz, $owner] = $this->shopWithPoints();

        $response = $this->actingAs($owner)->get('/settings?group=api');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('woo', null));
    }
}
