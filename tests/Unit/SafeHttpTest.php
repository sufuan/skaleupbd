<?php

// Goes in: tests/Unit/SafeHttpTest.php (new file). Needs no framework: covers the URL rules and DNS-result rules.
// Redirect handling and the no-send guarantee are covered in tests/Feature/OutboundWebhookTest.php.

namespace Tests\Unit;

use App\Services\Integrations\SafeHttp;
use App\Services\Integrations\UnsafeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeHttpTest extends TestCase
{
    public static function badUrls(): array
    {
        return array_map(fn ($u) => [$u], [
            'http://example.com/hook', 'ftp://example.com/hook', '//example.com/hook', 'example.com/hook', '',
            'https://localhost/hook', 'https://LOCALHOST./hook', 'https://127.0.0.1/hook', 'https://[::1]/hook',
            'https://169.254.169.254/latest/meta-data', 'https://2130706433/hook', 'https://0x7f.0.0.1/hook',
            'https://10.0.0.1/hook', 'https://user:pass@example.com/hook', 'https://example.com:8443/hook',
            'https://example.com:80/hook', 'https://intranet.local/hook', 'https://printer.lan/hook',
            'https://metadata.internal/hook', 'https://nodots/hook', "https://exa mple.com/hook",
            "https://example.com/hook\r\nHost: evil", 'https://example.com/'.str_repeat('a', 600),
        ]);
    }

    #[DataProvider('badUrls')]
    public function test_bad_urls_are_rejected_before_any_dns_lookup(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        SafeHttp::validateShape($url);
    }

    public function test_good_urls_pass_and_return_the_host(): void
    {
        $this->assertSame('shop.example.com', SafeHttp::validateShape('https://Shop.Example.com/skale/webhook?x=1'));
        $this->assertSame('xn--bcher-kva.example', SafeHttp::validateShape('https://xn--bcher-kva.example/h'));
        $this->assertSame('example.com', SafeHttp::validateShape('https://example.com:443/h'));
    }

    public function test_every_resolved_address_must_be_public(): void
    {
        $private = ['127.0.0.1', '169.254.169.254', '10.1.1.1', '192.168.0.9', '172.16.5.5', '100.64.0.2', '::1', 'fe80::1', '::ffff:10.0.0.1'];
        foreach ($private as $ip) {
            $http = new SafeHttp(fn () => [$ip]);
            try {
                $http->resolveSafe('https://shop.example.com/h');
                $this->fail("$ip should have been blocked");
            } catch (UnsafeUrlException $e) {
                $this->assertStringContainsString('private or internal', $e->getMessage());
            }
        }

        // One public and one private answer (split-horizon / rebinding trick) is still blocked.
        $mixed = new SafeHttp(fn () => ['93.184.216.34', '10.0.0.5']);
        $this->expectException(UnsafeUrlException::class);
        $mixed->resolveSafe('https://shop.example.com/h');
    }

    public function test_no_dns_answer_is_a_plain_failure(): void
    {
        $this->expectException(UnsafeUrlException::class);
        (new SafeHttp(fn () => []))->resolveSafe('https://shop.example.com/h');
    }

    public function test_public_answers_pass_and_ipv4_is_preferred(): void
    {
        $http = new SafeHttp(fn () => ['2606:4700:4700::1111', '93.184.216.34']);
        [$host, $ips] = $http->resolveSafe('https://shop.example.com/h');

        $this->assertSame('shop.example.com', $host);
        $this->assertSame('93.184.216.34', $ips[0]);
    }
}
