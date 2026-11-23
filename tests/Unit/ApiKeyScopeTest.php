<?php

// Goes in: tests/Unit/ApiKeyScopeTest.php (new file).
// Unit tests for ApiKey model scope logic. No Laravel boot required.

namespace Tests\Unit;

use App\Models\ApiKey;
use PHPUnit\Framework\TestCase;

class ApiKeyScopeTest extends TestCase
{
    private function key(array $scopes): ApiKey
    {
        $k = new ApiKey();
        $k->forceFill(['scopes' => $scopes]);

        return $k;
    }

    public function test_wildcard_grants_any_scope(): void
    {
        $k = $this->key(['*']);

        self::assertTrue($k->grants('orders.read'));
        self::assertTrue($k->grants('stock.read'));
        self::assertTrue($k->grants('anything'));
    }

    public function test_specific_scope_grants_only_that_scope(): void
    {
        $k = $this->key(['orders.read']);

        self::assertTrue($k->grants('orders.read'));
        self::assertFalse($k->grants('orders.write'));
        self::assertFalse($k->grants('stock.read'));
    }

    public function test_multiple_scopes(): void
    {
        $k = $this->key(['orders.read', 'stock.read']);

        self::assertTrue($k->grants('orders.read'));
        self::assertTrue($k->grants('stock.read'));
        self::assertFalse($k->grants('products.write'));
    }

    public function test_null_scopes_treated_as_full_access(): void
    {
        $k = $this->key([]);
        $k->forceFill(['scopes' => null]);

        // null scopes fallback to ['*'] per the grants() method
        self::assertTrue($k->grants('orders.read'));
    }

    public function test_revoked_flag(): void
    {
        $k = new ApiKey();
        $k->forceFill(['revoked_at' => now()]);
        self::assertTrue($k->isRevoked());

        $k2 = new ApiKey();
        $k2->forceFill(['revoked_at' => null]);
        self::assertFalse($k2->isRevoked());
    }
}
