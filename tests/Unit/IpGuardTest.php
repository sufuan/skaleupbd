<?php

// Goes in: tests/Unit/IpGuardTest.php (new file).

namespace Tests\Unit;

use App\Services\Integrations\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpGuardTest extends TestCase
{
    public static function blocked(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '127.255.255.254', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255',
            '192.168.0.1', '169.254.169.254', '169.254.0.1', '100.64.0.1', '100.127.255.255', '0.0.0.0',
            '255.255.255.255', '224.0.0.1', '240.0.0.1', '192.0.2.1', '198.18.0.1',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1',
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '64:ff9b::7f00:1',
            '2002:7f00:1::', '2001:db8::1', 'not-an-ip', '', '127.1', '2130706433', '0x7f.0.0.1',
        ]);
    }

    public static function allowed(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '8.8.8.8', '1.1.1.1', '93.184.216.34', '172.32.0.1', '172.15.255.255', '100.63.255.255',
            '100.128.0.1', '2606:4700:4700::1111', '2a00:1450:4001:81b::200e',
        ]);
    }

    #[DataProvider('blocked')]
    public function test_blocks_non_public_addresses(string $ip): void
    {
        $this->assertFalse(IpGuard::isPublic($ip), "$ip must be blocked");
    }

    #[DataProvider('allowed')]
    public function test_allows_public_addresses(string $ip): void
    {
        $this->assertTrue(IpGuard::isPublic($ip), "$ip must be allowed");
    }
}
