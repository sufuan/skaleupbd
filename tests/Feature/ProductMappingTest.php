<?php

// Goes in: tests/Feature/ProductMappingTest.php (new file).

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Our schema has real foreign keys to businesses (the kit's
        // fixtures assumed the rows exist): create shops 1 and 2.
        DB::table('businesses')->insertOrIgnore([
            ['id' => 1, 'name' => 'Shop One', 'public_id' => 'biz_public_1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Shop Two', 'public_id' => 'biz_public_2', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function setup45(int $bizA = 1, int $bizB = 2): array
    {
        $connA = DB::table('integration_connections')->insertGetId([
            'business_id'  => $bizA, 'platform' => 'woo', 'status' => 'active',
            'site_url'     => 'https://a.example', 'failure_count' => 0,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        $connB = DB::table('integration_connections')->insertGetId([
            'business_id'  => $bizB, 'platform' => 'woo', 'status' => 'active',
            'site_url'     => 'https://b.example', 'failure_count' => 0,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        $extId = DB::table('external_products')->insertGetId([
            'connection_id' => $connA, 'external_id' => 'WP-100',
            'external_variation_id' => '', 'sku' => 'SKU-X',
            'product_id' => null, 'state' => 'unmatched',
            'hash' => sha1('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $extB = DB::table('external_products')->insertGetId([
            'connection_id' => $connB, 'external_id' => 'WP-200',
            'external_variation_id' => '', 'sku' => null,
            'product_id' => null, 'state' => 'unmatched',
            'hash' => sha1('y'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $localA = DB::table('products')->insertGetId([
            'business_id' => $bizA, 'name' => 'Product A', 'sku' => 'SKU-X',
            'stock_qty' => 10, 'reserved_qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('connA', 'connB', 'extId', 'extB', 'localA');
    }

    private function userFor(int $biz): \App\Models\User
    {
        // A real owner user: the app's PermissionGate requires a genuine
        // User record (the kit's anonymous stub cannot pass it). The real
        // IntegrationHost reads the business from the user record.
        return \App\Models\User::factory()->create([
            'business_id' => $biz,
            'is_owner'    => true,
        ]);
    }

    // ------------------------------------------------------------------ unmatched list

    public function test_unmatched_returns_only_own_business_products(): void
    {
        $data = $this->setup45();

        $this->actingAs($this->userFor(1))
            ->getJson('/storefront/integrations/products/unmatched')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonFragment(['externalId' => 'WP-100']);
    }

    public function test_unmatched_does_not_leak_other_shop(): void
    {
        $data = $this->setup45();

        // Shop 1 cannot see Shop 2's unmatched products.
        $r = $this->actingAs($this->userFor(1))
            ->getJson('/storefront/integrations/products/unmatched')
            ->assertOk();

        $ids = collect($r->json('items'))->pluck('externalId')->all();
        $this->assertNotContains('WP-200', $ids);
    }

    // ------------------------------------------------------------------ match save

    public function test_match_links_external_to_local(): void
    {
        $data = $this->setup45();

        $this->actingAs($this->userFor(1))
            ->postJson('/storefront/integrations/products/match', [
                'externalProductId' => $data['extId'],
                'localProductId'    => $data['localA'],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('external_products', [
            'id'         => $data['extId'],
            'product_id' => $data['localA'],
            'state'      => 'matched',
        ]);
    }

    public function test_cannot_match_another_shops_external_product(): void
    {
        $data = $this->setup45();

        // Shop 1 tries to match Shop 2's external product.
        $this->actingAs($this->userFor(1))
            ->postJson('/storefront/integrations/products/match', [
                'externalProductId' => $data['extB'], // belongs to shop 2
                'localProductId'    => $data['localA'],
            ])
            ->assertStatus(404);

        // No change.
        $this->assertDatabaseHas('external_products', [
            'id'         => $data['extB'],
            'product_id' => null,
            'state'      => 'unmatched',
        ]);
    }

    public function test_cannot_match_to_another_shops_local_product(): void
    {
        $data = $this->setup45();
        $localB = DB::table('products')->insertGetId([
            'business_id' => 2, 'name' => 'B product', 'sku' => 'SKU-B',
            'stock_qty' => 5, 'reserved_qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->userFor(1))
            ->postJson('/storefront/integrations/products/match', [
                'externalProductId' => $data['extId'],
                'localProductId'    => $localB, // belongs to shop 2
            ])
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------ auto-match

    public function test_auto_match_by_sku(): void
    {
        $data = $this->setup45();

        $this->actingAs($this->userFor(1))
            ->postJson('/storefront/integrations/products/auto-match')
            ->assertOk()
            ->assertJsonFragment(['matched' => 1]);

        $this->assertDatabaseHas('external_products', [
            'id'    => $data['extId'],
            'state' => 'matched',
        ]);
    }

    // ------------------------------------------------------------------ clear match

    public function test_clear_match_sets_unmatched(): void
    {
        $data = $this->setup45();
        // First match it.
        DB::table('external_products')->where('id', $data['extId'])
            ->update(['product_id' => $data['localA'], 'state' => 'matched']);

        $this->actingAs($this->userFor(1))
            ->postJson('/storefront/integrations/products/match', [
                'externalProductId' => $data['extId'],
                'localProductId'    => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('external_products', [
            'id'    => $data['extId'],
            'state' => 'unmatched',
        ]);
    }
}
