<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ComboComponent;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DashboardStatsService;
use App\Services\OrderService;
use App\Services\TokenService;
use App\Support\DhakaTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round 3 fix batch (findings in Round3-Verification-Report.md):
 *  2. Inventory write controls + the create form hide behind the
 *     inventory edit tick (the server already refused the writes).
 *  3. The /module/* placeholder shells are gated by their module
 *     view ticks instead of opening for any signed-in user.
 *  4. The seller dashboard's Orders by Source value uses the one
 *     Booked definition: grand_total with Cancelled excluded.
 *  5. The Products list payload reports the derived sellable figure
 *     for combos and variant products (the picker definition).
 *  6. A check-in with NO shift assigned is Present, never Late.
 *  7. Order deletion from the list honours the delete tick and the
 *     Pending / Cancelled only rule the backend already enforces.
 */
class Round3FixBatchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Business, User} */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'R3 Shop', 'public_id' => 'r3-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'R3 Owner', 'email' => 'r3owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'r3 test', $owner->id);

        return [$biz, $owner];
    }

    /** A staff member whose role carries exactly the given ticks. */
    private function staff(Business $biz, array $ticks): User
    {
        $perms = [];
        foreach ($ticks as $module => $actions) {
            $perms[$module] = array_merge(
                ['view' => false, 'edit' => false, 'delete' => false, 'export' => false],
                $actions
            );
        }
        $role = Role::create(['business_id' => $biz->id, 'name' => 'R3 Role ' . uniqid(), 'permissions' => $perms]);

        return User::create([
            'name' => 'R3 Staff', 'email' => 'r3staff-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'role_id' => $role->id,
            'business_id' => $biz->id, 'staff_active' => true,
        ]);
    }

    private function makeOrder(Business $biz, User $owner, float $price = 1000): Order
    {
        $product = Product::create(['business_id' => $biz->id, 'name' => 'R3 Item ' . uniqid(), 'sku' => 'R3-' . uniqid(), 'price' => $price, 'cost' => 500, 'stock_qty' => 10]);

        return app(OrderService::class)->create($biz->id, [
            'customer_name' => 'R3 Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 1, Dhanmondi, Dhaka', 'district' => 'Dhaka',
            'source' => 'DIRECT', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => $price]],
        ], $owner, 'r3 test');
    }

    // ---------------------------------------------------------- fix 2

    // NOTE on shape: each method acts as ONE user throughout. The
    // app runs authenticateSessions(), so switching the signed-in
    // user mid-test makes the next request a logout redirect to the
    // login page - a harness artefact, not app behaviour.

    public function test_product_create_form_is_forbidden_for_view_only_staff(): void
    {
        [$biz] = $this->shop();
        $viewOnly = $this->staff($biz, ['inventory' => ['view' => true]]);

        // The list itself is a view surface and stays open.
        $this->actingAs($viewOnly)->get('/inventory/products')->assertOk();
        // The form page used to render in full for view-only staff.
        $this->actingAs($viewOnly)->get('/inventory/products/create')->assertForbidden();
    }

    public function test_product_create_form_opens_for_the_edit_tick(): void
    {
        [$biz] = $this->shop();
        $editor = $this->staff($biz, ['inventory' => ['view' => true, 'edit' => true]]);

        $this->actingAs($editor)->get('/inventory/products/create')->assertOk();
    }

    public function test_product_create_form_opens_for_the_owner(): void
    {
        [, $owner] = $this->shop();

        $this->actingAs($owner)->get('/inventory/products/create')->assertOk();
    }

    // ---------------------------------------------------------- fix 3

    public function test_module_placeholders_are_forbidden_without_any_ticks(): void
    {
        [$biz] = $this->shop();
        $noTicks = $this->staff($biz, []);

        // Support has no module key: shell chrome, unchanged.
        $this->actingAs($noTicks)->get('/module/support')->assertOk();
        $this->actingAs($noTicks)->get('/module/task')->assertForbidden();
        $this->actingAs($noTicks)->get('/module/call-center')->assertForbidden();
        $this->actingAs($noTicks)->get('/module/ads')->assertForbidden();
    }

    public function test_module_placeholder_opens_for_its_own_view_tick_only(): void
    {
        [$biz] = $this->shop();
        $taskViewer = $this->staff($biz, ['tasks' => ['view' => true]]);

        $this->actingAs($taskViewer)->get('/module/task')->assertOk();
        $this->actingAs($taskViewer)->get('/module/ads')->assertForbidden();
        $this->actingAs($taskViewer)->get('/module/call-center')->assertForbidden();
    }

    public function test_call_center_placeholder_opens_for_the_callcenter_tick(): void
    {
        [$biz] = $this->shop();
        $callViewer = $this->staff($biz, ['callcenter' => ['view' => true]]);

        $this->actingAs($callViewer)->get('/module/call-center')->assertOk();
    }

    public function test_ads_placeholder_opens_for_the_ads_tick(): void
    {
        [$biz] = $this->shop();
        $adsViewer = $this->staff($biz, ['ads' => ['view' => true]]);

        $this->actingAs($adsViewer)->get('/module/ads')->assertOk();
    }

    public function test_module_placeholders_open_for_the_owner(): void
    {
        [, $owner] = $this->shop();

        $this->actingAs($owner)->get('/module/task')->assertOk();
        $this->actingAs($owner)->get('/module/call-center')->assertOk();
        $this->actingAs($owner)->get('/module/ads')->assertOk();
    }

    // ---------------------------------------------------------- fix 4

    public function test_dashboard_source_value_excludes_cancelled_orders(): void
    {
        [$biz, $owner] = $this->shop();
        $kept = $this->makeOrder($biz, $owner, 1000);   // grand_total 1060
        $dropped = $this->makeOrder($biz, $owner, 750); // grand_total 810
        $dropped->forceFill(['status' => 'Cancelled'])->save();

        $stats = app(DashboardStatsService::class)->myDashboard($biz->id, DhakaTime::today(), 'created');

        $direct = collect($stats['sources'])->firstWhere('label', 'DIRECT')
            ?? collect($stats['sources'])->first();
        $this->assertNotNull($direct);
        $this->assertSame(2, $direct['count'], 'the count stays the count of orders received');
        $this->assertEqualsWithDelta((float) $kept->grand_total, (float) $direct['value'], 0.01,
            'the source value is the Booked definition: Cancelled excluded');

        $channelsTotal = collect($stats['channels'])->sum('value');
        $this->assertEqualsWithDelta((float) $kept->grand_total, (float) $channelsTotal, 0.01,
            'the channel rows carry the same definition');
    }

    // ---------------------------------------------------------- fix 5

    public function test_products_payload_reports_derived_stock_for_combos_and_variants(): void
    {
        [$biz, $owner] = $this->shop();

        $part = Product::create(['business_id' => $biz->id, 'name' => 'A Part', 'sku' => 'R3-PART', 'price' => 100, 'cost' => 50, 'stock_qty' => 14]);
        $combo = Product::create(['business_id' => $biz->id, 'name' => 'B Combo', 'sku' => 'R3-COMBO', 'price' => 250, 'cost' => 100, 'stock_qty' => 0, 'type' => 'combo']);
        ComboComponent::create(['business_id' => $biz->id, 'combo_product_id' => $combo->id, 'component_product_id' => $part->id, 'qty' => 2]);
        $variantProduct = Product::create(['business_id' => $biz->id, 'name' => 'C Variant', 'sku' => 'R3-VAR', 'price' => 850, 'cost' => 500, 'stock_qty' => 0]);
        ProductVariant::create(['business_id' => $biz->id, 'product_id' => $variantProduct->id, 'name' => 'M', 'price' => 850, 'stock_qty' => 10, 'reserved_qty' => 2]);
        ProductVariant::create(['business_id' => $biz->id, 'product_id' => $variantProduct->id, 'name' => 'L', 'price' => 850, 'stock_qty' => 7, 'reserved_qty' => 0]);

        $response = $this->actingAs($owner)->get('/inventory/products');
        $response->assertOk();
        // Products are ordered by name: A Part, B Combo, C Variant.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/Products')
            ->where('products.data.0.available', 14)
            ->where('products.data.1.available', 7)
            ->where('products.data.2.available', 15)
            ->where('products.data.2.reserved_qty', 2)
            ->where('products.data.2.has_variants', true)
            ->where('products.data.0.has_variants', false));
    }

    // ---------------------------------------------------------- fix 6

    public function test_checkin_without_a_shift_is_present_never_late(): void
    {
        [$biz] = $this->shop();
        $noShift = $this->staff($biz, ['hrm' => ['view' => true, 'edit' => true]]);

        $record = app(AttendanceService::class)->checkIn($noShift);

        $this->assertSame('present', $record->status);
        $this->assertSame(0, (int) $record->late_minutes);
    }

    public function test_checkin_with_a_shift_keeps_the_late_math(): void
    {
        [$biz] = $this->shop();
        $withShift = $this->staff($biz, ['hrm' => ['view' => true, 'edit' => true]]);
        // A shift that started three Dhaka hours ago: well past the
        // 15-minute grace, so the with-shift math must still say Late.
        $start = now('Asia/Dhaka')->subHours(3)->format('H:i');
        $shift = Shift::create(['business_id' => $biz->id, 'name' => 'R3 Shift', 'start_time' => $start, 'end_time' => '23:59']);
        $withShift->shifts()->attach($shift->id);

        $record = app(AttendanceService::class)->checkIn($withShift->fresh());

        $this->assertSame('late', $record->status);
        $this->assertGreaterThan(15, (int) $record->late_minutes);
    }

    // ---------------------------------------------------------- fix 7

    public function test_order_delete_honours_the_status_rule_with_the_tick(): void
    {
        [$biz, $owner] = $this->shop();
        $deleter = $this->staff($biz, ['orders' => ['view' => true, 'edit' => true, 'delete' => true]]);

        // Pending + delete tick: deleted.
        $pending = $this->makeOrder($biz, $owner);
        $this->actingAs($deleter)->delete('/orders/' . $pending->id)
            ->assertRedirect(route('orders.index'));
        $this->assertDatabaseMissing('orders', ['id' => $pending->id]);

        // Cancelled + delete tick: deleted.
        $cancelled = $this->makeOrder($biz, $owner);
        $cancelled->forceFill(['status' => 'Cancelled'])->save();
        $this->actingAs($deleter)->delete('/orders/' . $cancelled->id)
            ->assertRedirect(route('orders.index'));
        $this->assertDatabaseMissing('orders', ['id' => $cancelled->id]);

        // Processing: the backend rule refuses even with the tick.
        $processing = $this->makeOrder($biz, $owner);
        $processing->forceFill(['status' => 'Processing'])->save();
        $this->actingAs($deleter)->delete('/orders/' . $processing->id);
        $this->assertDatabaseHas('orders', ['id' => $processing->id]);
    }

    public function test_order_delete_is_forbidden_without_the_delete_tick(): void
    {
        [$biz, $owner] = $this->shop();
        $noDelete = $this->staff($biz, ['orders' => ['view' => true, 'edit' => true]]);

        // No delete tick: the gate refuses before the controller.
        $pending = $this->makeOrder($biz, $owner);
        $this->actingAs($noDelete)->delete('/orders/' . $pending->id)->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $pending->id]);
    }
}
