<?php

// Goes in: tests/Feature/ApiKeyHardeningTest.php (new file).
// Tests API key scopes, revocation, cross-tenant isolation, and business-from-key derivation.
// Run with: php artisan test tests/Feature/ApiKeyHardeningTest.php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\Integrations\IntegrationHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeIntegrationHost;
use Tests\TestCase;

class ApiKeyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private FakeIntegrationHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        // Our schema has real foreign keys to businesses (the kit's
        // fixtures assumed the rows exist): create shops 1 and 2.
        \DB::table('businesses')->insertOrIgnore([
            ['id' => 1, 'name' => 'Shop One', 'public_id' => 'biz_public_1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Shop Two', 'public_id' => 'biz_public_2', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // SkaleUp stores API key hashes as sha256 (the kit's fixtures used
        // bcrypt; converted below in makeKey). Points come from the fake
        // host so the suspension checks pass for funded shops; the real
        // TokenService wiring is proven by the install gate script.
        $this->host = new FakeIntegrationHost();
        $this->host->points = [1 => 5, 2 => 5];
        $this->app->instance(IntegrationHost::class, $this->host);
    }

    // ------------------------------------------------------------------ setup helpers

    private function makeKey(int $businessId, array $scopes, bool $revoked = false): array
    {
        $raw = 'sk_' . str_repeat('a', 48);
        ApiKey::create([
            'business_id' => $businessId,
            'key_hash'    => hash('sha256', $raw),
            'last4'       => substr($raw, -4),
            'scopes'      => $scopes,
            'revoked_at'  => $revoked ? now() : null,
        ]);

        return [$raw];
    }

    // ------------------------------------------------------------------ scope tests

    public function test_full_access_key_reaches_all_endpoints(): void
    {
        [$raw] = $this->makeKey(1, ['*']);

        $this->withHeaders(['x-api-key' => $raw])
            ->postJson('/api/external/v1/stock/check', ['items' => [['externalId' => 'X1']]])
            ->assertSuccessful();
    }

    public function test_scoped_key_blocked_on_wrong_scope(): void
    {
        [$raw] = $this->makeKey(1, ['orders.read']); // has orders.read but NOT stock.read

        $this->withHeaders(['x-api-key' => $raw])
            ->postJson('/api/external/v1/stock/check', ['items' => [['externalId' => 'X1']]])
            ->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_scoped_key_allowed_on_correct_scope(): void
    {
        [$raw] = $this->makeKey(1, ['orders.read']);

        // GET order status requires orders.read — should be allowed
        // (order won't exist so it returns 404, but middleware passes)
        $this->withHeaders(['x-api-key' => $raw])
            ->getJson('/api/external/v1/orders/NONEXISTENT')
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------ revocation tests

    public function test_revoked_key_is_rejected(): void
    {
        [$raw] = $this->makeKey(1, ['*'], revoked: true);

        $this->withHeaders(['x-api-key' => $raw])
            ->getJson('/api/external/v1/orders/X1')
            ->assertStatus(401);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->getJson('/api/external/v1/orders/X1')
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------ cross-tenant isolation

    public function test_shop_a_key_cannot_read_shop_b_order(): void
    {
        // Create a key for business 1
        [$raw] = $this->makeKey(1, ['orders.read']);

        // Create an order that belongs to business 2
        $this->createTestOrder(businessId: 2, externalRef: 'ORDER-B-999');

        // Business 1's key must not see business 2's order
        $this->withHeaders(['x-api-key' => $raw])
            ->getJson('/api/external/v1/orders/ORDER-B-999')
            ->assertStatus(404); // 404 not 403: we don't confirm the order exists in another shop
    }

    public function test_revoke_endpoint_scoped_to_own_business(): void
    {
        // Build two businesses
        [$rawA] = $this->makeKey(1, ['*']);
        $keyB = ApiKey::create([
            'business_id' => 2,
            'key_hash'    => hash('sha256', 'sk_' . str_repeat('b', 48)),
            'last4'       => 'bbbb',
            'scopes'      => ['*'],
        ]);

        // Log in as business 1 seller and try to revoke business 2's key
        $this->actingAs($this->makeUser(businessId: 1))
            ->deleteJson('/storefront/integrations/keys/' . $keyB->id)
            ->assertStatus(404); // findOrFail scoped to business_id=1 returns 404
    }

    // ------------------------------------------------------------------ validation

    public function test_create_key_rejects_invalid_scope(): void
    {
        $this->actingAs($this->makeUser(businessId: 1))
            ->postJson('/storefront/integrations/keys', ['scopes' => ['root.access']])
            ->assertStatus(422);
    }

    public function test_create_key_requires_at_least_one_scope(): void
    {
        $this->actingAs($this->makeUser(businessId: 1))
            ->postJson('/storefront/integrations/keys', ['scopes' => []])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------ product bulk-upsert

    public function test_product_upsert_max_100(): void
    {
        [$raw] = $this->makeKey(1, ['products.write']);

        $products = array_map(fn ($i) => ['externalId' => 'P' . $i], range(1, 101));

        $this->withHeaders(['x-api-key' => $raw])
            ->postJson('/api/external/v1/products', ['products' => $products])
            ->assertStatus(422);
    }

    public function test_product_upsert_auto_matches_by_sku(): void
    {
        [$raw] = $this->makeKey(1, ['products.write']);

        // Create a local product with a known SKU
        $productId = \DB::table('products')->insertGetId([
            'business_id' => 1,
            'sku'         => 'SKU-XYZ',
            'name'        => 'Test product',
            'stock_qty'   => 10,
            'reserved_qty' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Create the connection
        $connId = \DB::table('integration_connections')->insertGetId([
            'business_id' => 1,
            'platform'    => 'custom',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->withHeaders(['x-api-key' => $raw])
            ->postJson('/api/external/v1/products', [
                'products' => [['externalId' => 'EXT-1', 'sku' => 'SKU-XYZ']],
            ])
            ->assertSuccessful()
            ->assertJsonFragment(['matched' => 1, 'unmatched' => 0]);

        $this->assertDatabaseHas('external_products', [
            'connection_id' => $connId,
            'external_id'   => 'EXT-1',
            'product_id'    => $productId,
            'state'         => 'matched',
        ]);
    }

    public function test_stock_check_returns_null_for_unmatched(): void
    {
        [$raw] = $this->makeKey(1, ['stock.read']);

        // No connection, so nothing matched
        $this->withHeaders(['x-api-key' => $raw])
            ->postJson('/api/external/v1/stock/check', [
                'items' => [['externalId' => 'GHOST']],
            ])
            ->assertSuccessful()
            ->assertJsonFragment(['matched' => false, 'available' => null]);
    }

    // ------------------------------------------------------------------ stand-in helpers

    private function createTestOrder(int $businessId, string $externalRef): void
    {
        // The real orders table also requires invoice_no, customer_name
        // and customer_phone (the kit's minimal insert predates them).
        \DB::table('orders')->insert([
            'business_id'   => $businessId,
            'external_ref'  => $externalRef,
            'status'        => 'Pending',
            'invoice_no'    => 'INV-' . $externalRef,
            'customer_name' => 'Test Customer',
            'customer_phone' => '01700000000',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function makeUser(int $businessId): User
    {
        // A real owner user: the app's PermissionGate needs a genuine
        // User record (the kit's anonymous stub cannot pass it).
        $user = User::factory()->create([
            'business_id' => $businessId,
            'is_owner'    => true,
        ]);
        $this->host->userBusiness[$user->id] = $businessId;

        return $user;
    }
}
