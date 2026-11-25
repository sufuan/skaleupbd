<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\BusinessProvisioner;
use App\Services\DeliveryMethodList;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the fresh-seller blocker found in the
 * clean-slate Round 1 Phase 1 run (2026-11-26): a brand-new shop had
 * ZERO courier_methods rows, so the New Order form's Delivery Method
 * dropdown offered only its placeholder and the seller could not take
 * an order until they first wandered into Settings -> API Connection
 * and added a courier by hand. Provisioning must hand every new shop
 * one ready-to-use manual courier method instead.
 */
class DefaultCourierMethodTest extends TestCase
{
    use RefreshDatabase;

    private function registerShop(string $email, string $shopName): Business
    {
        // A signed-in owner who hits the register route is bounced home
        // instead of registered; start each registration as a guest.
        if (auth()->check()) {
            $this->post(route('logout'));
        }

        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();

        $response = $this->post(route('register.store'), [
            'name' => 'New Seller',
            'shop_name' => $shopName,
            'email' => $email,
            'mobile' => '01712345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'pack_id' => $pack->id,
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));

        return Business::where('email', $email)->firstOrFail();
    }

    public function test_registration_gives_the_fresh_shop_one_default_manual_courier_method(): void
    {
        $biz = $this->registerShop('fresh-courier@example.com', 'Fresh Courier Shop');

        $methods = CourierMethod::where('business_id', $biz->id)->get();
        $this->assertCount(1, $methods, 'a fresh shop must start with exactly one courier method');

        $method = $methods->sole();
        $this->assertSame('OTHERS', $method->courier_type);
        $this->assertSame('Manual (Other)', $method->label());
        $this->assertTrue((bool) $method->is_active);
        $this->assertTrue((bool) $method->is_default);
        $this->assertEqualsWithDelta(60.0, (float) $method->charge_inside_dhaka, 0.01);
        $this->assertEqualsWithDelta(120.0, (float) $method->charge_outside_dhaka, 0.01);

        // The New Order form's dropdown source: the manual method's name.
        $this->assertSame(['Manual (Other)'], app(DeliveryMethodList::class)->forBusiness($biz->id));
    }

    public function test_provisioning_never_duplicates_the_default_method(): void
    {
        $biz = $this->registerShop('dupe-courier@example.com', 'Dupe Courier Shop');
        $this->assertSame(1, CourierMethod::where('business_id', $biz->id)->count());

        // Re-running the seed install (backfill path) must add nothing.
        $provisioner = app(BusinessProvisioner::class);
        $provisioner->ensureSeeds($biz);
        $provisioner->ensureSeeds($biz);
        $this->assertSame(1, CourierMethod::where('business_id', $biz->id)->count());

        // A second shop gets its own single method — no sharing, no extras.
        $other = $this->registerShop('second-courier@example.com', 'Second Courier Shop');
        $this->assertSame(1, CourierMethod::where('business_id', $other->id)->count());
        $this->assertSame(2, CourierMethod::count());
    }

    public function test_a_shop_that_already_has_a_courier_gets_no_default_added(): void
    {
        // A shop written directly (no provisioning yet), with a real
        // courier connection already in place.
        $bizId = DB::table('businesses')->insertGetId([
            'name' => 'Connected Shop', 'public_id' => 'connected-shop', 'status' => 'approved',
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        CourierMethod::create([
            'business_id' => $bizId, 'name' => 'Pathao', 'courier_type' => 'PATHAO',
            'credentials' => ['client_id' => 'x'], 'charge_inside_dhaka' => 70,
            'charge_outside_dhaka' => 130, 'is_active' => true, 'is_default' => true,
        ]);

        app(BusinessProvisioner::class)->ensureSeeds(Business::findOrFail($bizId));

        $methods = CourierMethod::where('business_id', $bizId)->get();
        $this->assertCount(1, $methods);
        $this->assertSame('PATHAO', $methods->sole()->courier_type);
    }

    public function test_backfill_migration_fills_a_pre_existing_courier_less_business(): void
    {
        $bizId = DB::table('businesses')->insertGetId([
            'name' => 'Legacy Shop', 'public_id' => 'legacy-courier-shop', 'status' => 'approved',
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, CourierMethod::where('business_id', $bizId)->count());

        $migration = require database_path('migrations/2026_11_26_000066_backfill_default_courier_method.php');
        $migration->up();

        $methods = CourierMethod::where('business_id', $bizId)->get();
        $this->assertCount(1, $methods);
        $this->assertSame('OTHERS', $methods->sole()->courier_type);
        $this->assertTrue((bool) $methods->sole()->is_default);

        // Running it again changes nothing.
        $migration->up();
        $this->assertSame(1, CourierMethod::where('business_id', $bizId)->count());
    }

    public function test_an_order_can_be_created_on_a_truly_fresh_shop(): void
    {
        $biz = $this->registerShop('order-courier@example.com', 'Order Courier Shop');
        $owner = User::where('business_id', $biz->id)->firstOrFail();

        // Platform approval + a points pack, as in the real first-run flow.
        $biz->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'first pack', $owner->id);

        $product = Product::create([
            'business_id' => $biz->id, 'name' => 'Fresh Panjabi', 'sku' => 'FP-1',
            'price' => 1300, 'cost' => 900, 'stock_qty' => 20,
        ]);

        // The create form itself must offer the method (this is the exact
        // prop the Delivery Method dropdown renders).
        $page = $this->actingAs($owner)->get(route('orders.create'));
        $page->assertOk();
        $page->assertInertia(fn ($p) => $p->component('Orders/Create')
            ->where('couriers', ['Manual (Other)']));

        $pointsBefore = (int) DB::table('business_wallets')->where('business_id', $biz->id)->value('balance');
        $this->assertGreaterThan(0, $pointsBefore);

        $response = $this->actingAs($owner)->post(route('orders.store'), [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '01720000001',
            'customer_address' => 'House 1, Road 2, Dhanmondi, Dhaka',
            'district' => 'Dhaka',
            'source' => 'DIRECT',
            'delivery_method' => 'Manual (Other)',
            'delivery_charge' => 60,
            'mode' => 'approved',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Fresh Panjabi', 'qty' => 1, 'price' => 1300],
            ],
        ]);
        $response->assertRedirect();

        $order = Order::where('business_id', $biz->id)->sole();
        $this->assertSame('Manual (Other)', $order->delivery_method);
        $this->assertEqualsWithDelta(1360.0, (float) $order->grand_total, 0.01);

        $pointsAfter = (int) DB::table('business_wallets')->where('business_id', $biz->id)->value('balance');
        $this->assertSame($pointsBefore - 1, $pointsAfter, 'exactly one point must be deducted for the order');
    }
}
