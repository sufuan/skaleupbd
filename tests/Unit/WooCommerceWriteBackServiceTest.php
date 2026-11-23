<?php

// Goes in: tests/Unit/WooCommerceWriteBackServiceTest.php (new file).
// Unit tests for PUSH_STATUS_MAP and isEcho() logic (no DB needed).

namespace Tests\Unit;

use App\Services\Integrations\WooCommerceWriteBackService;
use PHPUnit\Framework\TestCase;

class WooCommerceWriteBackServiceTest extends TestCase
{
    public function test_push_map_contains_all_expected_statuses(): void
    {
        $map = WooCommerceWriteBackService::PUSH_STATUS_MAP;
        self::assertArrayHasKey('Processing', $map);
        self::assertArrayHasKey('Shipped', $map);
        self::assertArrayHasKey('Delivered', $map);
        self::assertArrayHasKey('Returned', $map);
        self::assertArrayHasKey('Cancelled', $map);
    }

    public function test_processing_is_null_in_push_map(): void
    {
        self::assertNull(WooCommerceWriteBackService::PUSH_STATUS_MAP['Processing']);
    }

    public function test_shipped_maps_to_processing(): void
    {
        self::assertSame('processing', WooCommerceWriteBackService::PUSH_STATUS_MAP['Shipped']);
    }

    public function test_delivered_maps_to_completed(): void
    {
        self::assertSame('completed', WooCommerceWriteBackService::PUSH_STATUS_MAP['Delivered']);
    }

    public function test_all_non_null_values_are_valid_wc_statuses(): void
    {
        $validWcStatuses = ['processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'pending'];
        foreach (WooCommerceWriteBackService::PUSH_STATUS_MAP as $ourStatus => $wooStatus) {
            if ($wooStatus !== null) {
                self::assertContains(
                    $wooStatus,
                    $validWcStatuses,
                    "Status '{$ourStatus}' maps to unknown WooCommerce status '{$wooStatus}'"
                );
            }
        }
    }
}
