<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\AiClassifierService;
use App\Services\OrderService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 13 (2026-11-25, Step 5 of the external expert
 * review; findings #6 and #7). The reviewer read only
 * RuleBrainService.php + AiRule.php; both findings were verified
 * against the whole codebase before fixing:
 *
 *  #6 QUANTITY — a chat order was always x1. buildSummary wrote
 *     "(×1)" and the list price as the line total, the cart snapshot
 *     stored qty 1, and createOrderFromSummary created the item
 *     with 'qty' => 1: "২টা লাগবে" still produced a one-piece
 *     summary and a one-piece order.
 *
 *  #7 DELIVERY CHARGE BY AREA — buildSummary always charged the
 *     shop's default (inside-Dhaka) delivery cost, whatever the
 *     customer's district, and the outside-Dhaka figure the rules
 *     quote ({delivery_outside}) was a hardcoded 120 in
 *     buildReply/renderSlots/allowedFigures — no shop setting
 *     existed for it anywhere.
 */
class BrainFix13Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent, as in the
        // earlier brain suites: the deterministic layer is under test.
        $stub = new class extends AiClassifierService
        {
            public function __construct()
            {
            }

            public function classify(int $businessId, string $text): ?array
            {
                return null;
            }
        };
        app()->instance(AiClassifierService::class, $stub);
    }

    private function setMode(string $mode): void
    {
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => $mode, 'whatsapp' => $mode,
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    /**
     * One product (CRUD Test Panjabi, 1,300, ladder 1,235 / 1,170 /
     * floor 1,105), a points wallet, a fresh focused conversation.
     *
     * @return array{Business, User, Product, Conversation}
     */
    private function shop(int $stock = 10): array
    {
        $biz = Business::create(['name' => 'Batch13 Shop', 'public_id' => 'batch13-shop-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Batch13 Owner', 'email' => 'batch13-owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix13 test', $owner->id);

        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => $stock]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $owner, $p, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function balance(int $bizId): int
    {
        return (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
    }

    private function orderCount(int $bizId): int
    {
        return Order::where('business_id', $bizId)->count();
    }

    private function setOutside(int $bizId, float $value): void
    {
        app(SettingsService::class)->set("business.{$bizId}.default_delivery_cost_outside", $value);
    }

    // ------------------------------------------------------------- #6 qty

    /** The quantity parser: digits in both scripts, written-out
     *  Bengali numbers, romanised numbers — and the lookalikes that
     *  must never parse (phone numbers, prices, addresses). */
    public function test_quantity_parsing_variants(): void
    {
        $brain = app(RuleBrainService::class);

        $this->assertSame(2, $brain->extractQuantity('২টা লাগবে'));
        $this->assertSame(2, $brain->extractQuantity('2 ta nibo'));
        $this->assertSame(3, $brain->extractQuantity('৩ পিস দিয়েন'));
        $this->assertSame(3, $brain->extractQuantity('3 pcs please'));
        $this->assertSame(2, $brain->extractQuantity('dui ta lagbe'));
        $this->assertSame(2, $brain->extractQuantity('২ টা নিব'));
        $this->assertSame(3, $brain->extractQuantity('তিনটা নিব'));
        $this->assertSame(3, $brain->extractQuantity('৩টাই করেন'));
        $this->assertSame(5, $brain->extractQuantity('৫টা আছে?'));

        $this->assertNull($brain->extractQuantity('phone 01612345678'), 'a phone number is not a quantity');
        $this->assertNull($brain->extractQuantity('dam 1300 taka'), 'a price is not a quantity');
        $this->assertNull($brain->extractQuantity('১২০০ টাকায় দিব'), 'টাকায় must not read as টা');
        $this->assertNull($brain->extractQuantity('House 10, Road 5, Uttara, Dhaka'), 'an address is not a quantity');
        $this->assertNull($brain->extractQuantity('2'), 'a bare pick is not a quantity');
        $this->assertNull($brain->extractQuantity('thik ache, CRUD-102 ta 808 takay nibo'), 'digits glued to a product code are the code, not a quantity');
    }

    /** A quantity stated with the order rides the whole flow: the
     *  summary shows x3 with the exact line total, and the created
     *  order carries qty 3 — one delivery charge, one point. */
    public function test_summary_and_order_carry_the_quantity(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai, ৩টা নিব. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(3, (int) ($summary->meta['qty'] ?? 0), 'the summary snapshot carries the quantity');
        $this->assertSame(1300.0, (float) ($summary->meta['unit_price'] ?? 0), 'the unit price stays per-piece');
        $this->assertSame(3960.0, (float) ($summary->meta['total'] ?? 0), '1,300 x 3 + 60 delivery, charged once');
        $this->assertStringContainsString('(×3)', $summary->body);
        $this->assertStringContainsString('3,900', $summary->body, 'the line total is unit x qty');
        $this->assertStringContainsString('ডেলিভারি চার্জ: 60', $summary->body, 'one delivery charge for the parcel');

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point, however many pieces');

        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $item = $order->items()->first();
        $this->assertSame(3, (int) $item->qty, 'the order line carries the quantity');
        $this->assertEqualsWithDelta(60.0, (float) $order->delivery_charge, 0.01);
        $this->assertEqualsWithDelta(3960.0, (float) $order->grand_total, 0.01, 'the order total equals the summary total exactly');
    }

    /** Delivery of a x3 chat order consumes exactly 3 units of
     *  stock across the whole journey — reserved at approval,
     *  consumed once, never twice. */
    public function test_delivery_consumes_the_full_quantity_from_stock(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'CRUD-101 order korte chai, ৩টা নিব. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->send($brain, $conv, 'ha, confirm korchi');
        $order = Order::where('business_id', $biz->id)->firstOrFail();

        $orders = app(OrderService::class);
        $orders->approve($order->fresh(), $owner);
        $this->assertSame(10, (int) $p->fresh()->stock_qty, 'approval reserves, it does not consume');
        $this->assertSame(3, (int) $p->fresh()->reserved_qty, 'all 3 pieces are held');

        $orders->changeStatus($order->fresh(), 'Shipped', $owner, 'test ship');
        $orders->changeStatus($order->fresh(), 'Delivered', $owner, 'test delivery');
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(7, (int) $p->fresh()->stock_qty, 'exactly 3 units left the shelf');
        $this->assertSame(0, (int) $p->fresh()->reserved_qty, 'the reservation is fully released');
        $this->assertSame(7, (int) $p->fresh()->available());
    }

    /** Asking for more than the shelf holds is answered honestly —
     *  how many ARE available — never with a summary or a short
     *  order. Lowering the ask to what exists completes normally. */
    public function test_quantity_above_stock_is_answered_honestly(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop(stock: 2);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'CRUD-101 order korte chai, ৫টা নিব. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($r);
        $this->assertNotSame('order_summary', $r->meta['kind'] ?? null, 'no summary for an unfulfillable quantity');
        $this->assertStringContainsString('2টা আছে', $r->body, 'the reply says how many ARE available');
        $this->assertSame(0, $this->orderCount($biz->id), 'no short order was created');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['pending_summary'] ?? null, 'nothing confirmable was left on the table');

        $summary = $this->send($brain, $conv, 'তাহলে ২টা নিব');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(2, (int) ($summary->meta['qty'] ?? 0));
        $this->assertSame(2660.0, (float) ($summary->meta['total'] ?? 0), '1,300 x 2 + 60');
    }

    /** A quantity change while a summary is on the table re-renders
     *  the summary at the new quantity — the order is created only
     *  from the NEW figures, never the stale ones. */
    public function test_quantity_change_before_confirmation_rerenders_the_summary(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame(1360.0, (float) ($summary->meta['total'] ?? 0), 'the first summary is x1');

        $r = $this->send($brain, $conv, 'না, ৩টা করেন');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null, 'the change re-renders the summary');
        $this->assertSame(3, (int) ($r->meta['qty'] ?? 0));
        $this->assertSame(3960.0, (float) ($r->meta['total'] ?? 0));
        $this->assertSame(0, $this->orderCount($biz->id), 'the change itself creates no order');

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame(3, (int) $order->items()->first()->qty);
        $this->assertEqualsWithDelta(3960.0, (float) $order->grand_total, 0.01);
    }

    /** Bargaining stays per-unit when a quantity rides along: the
     *  ladder settles the PIECE price, the summary multiplies it. */
    public function test_bargaining_stays_per_unit_with_a_quantity(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertSame(1235.0, (float) ($offer->meta['offer'] ?? 0), 'the first ladder rung, per piece');

        $summary = $this->send($brain, $conv, 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka. ২টা নিব।');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(1235.0, (float) ($summary->meta['unit_price'] ?? 0), 'the bargained piece price');
        $this->assertSame(2, (int) ($summary->meta['qty'] ?? 0));
        $this->assertSame(2530.0, (float) ($summary->meta['total'] ?? 0), '1,235 x 2 + 60 delivery');
    }

    // ------------------------------------------- #7 delivery charge by area

    /** An outside-Dhaka address is charged the outside rate in the
     *  summary AND in the created order — the two always agree. */
    public function test_outside_dhaka_address_gets_the_outside_charge(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 5, Road 2, Chattogram.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(120.0, (float) ($summary->meta['delivery'] ?? 0), 'the default outside charge, until the shop sets its own');
        $this->assertSame(1420.0, (float) ($summary->meta['total'] ?? 0), '1,300 + 120');
        $this->assertStringContainsString('ডেলিভারি চার্জ: 120', $summary->body);

        $this->send($brain, $conv, 'ha, confirm korchi');
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $order->delivery_charge, 0.01, 'the order charges what the summary quoted');
        $this->assertEqualsWithDelta(1420.0, (float) $order->grand_total, 0.01);
    }

    /** The outside figure is the SHOP'S setting now: changing it
     *  changes the next summary, and the inside rate is untouched. */
    public function test_the_outside_charge_comes_from_the_shop_setting(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->setOutside($biz->id, 150);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 5, Road 2, Sylhet.');
        $this->assertNotNull($summary);
        $this->assertSame(150.0, (float) ($summary->meta['delivery'] ?? 0));
        $this->assertSame(1450.0, (float) ($summary->meta['total'] ?? 0), '1,300 + the shop-set 150');
    }

    /** A delivery-charge question that names its area is answered
     *  with THAT area's charge — inside and outside alike — and the
     *  figures follow the shop's settings. (The reviewer's catalog
     *  'Area charge' case, implemented here once.) */
    public function test_area_charge_question_names_its_area(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'চট্টগ্রামে চার্জ কত?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('চট্টগ্রাম', $r->body);
        $this->assertStringContainsString('120', $r->body, 'the outside charge for an outside district');

        $r = $this->send($brain, $conv, 'ঢাকায় চার্জ কত?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('60', $r->body, 'the inside charge for Dhaka itself');

        $this->setOutside($biz->id, 150);
        $r = $this->send($brain, $conv, 'সিলেটে চার্জ কত?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('150', $r->body, 'the answer follows the shop setting');
    }

    /** The outside charge is a Business Profile setting like the
     *  inside one: it defaults to 120, round-trips through the
     *  settings service, and the profile form saves it. */
    public function test_outside_charge_setting_round_trip_and_form_save(): void
    {
        [$biz, $owner] = $this->shop();
        $settings = app(SettingsService::class);

        $this->assertSame(120.0, (float) $settings->bizGet($biz->id, "business.{$biz->id}.default_delivery_cost_outside", 'business.default_delivery_cost_outside', 120));

        $response = $this->actingAs($owner)->post('/settings/business', [
            'name' => 'Batch13 Shop',
            'mobile' => '01712345678',
            'address' => 'Dhaka',
            'invoice_slug' => 'OB',
            'default_delivery_cost' => 70,
            'default_delivery_cost_outside' => 150,
        ]);
        $response->assertSessionHas('success');
        $this->assertSame(150.0, (float) $settings->bizGet($biz->id, "business.{$biz->id}.default_delivery_cost_outside", 'business.default_delivery_cost_outside', 120));
        $this->assertSame(70.0, (float) $settings->bizGet($biz->id, "business.{$biz->id}.default_delivery_cost", 'business.default_delivery_cost', 60));
    }
}
