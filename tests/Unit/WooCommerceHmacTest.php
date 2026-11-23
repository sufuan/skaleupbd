<?php

// Goes in: tests/Unit/WooCommerceHmacTest.php (new file).
// Unit tests for WooCommerceHmac — no DB, no Laravel boot needed.

namespace Tests\Unit;

use App\Services\Integrations\WooCommerceHmac;
use PHPUnit\Framework\TestCase;

class WooCommerceHmacTest extends TestCase
{
    private string $secret = 'my_webhook_secret';
    private string $body   = '{"id":42,"status":"processing"}';

    public function test_sign_and_verify_round_trip(): void
    {
        $sig = WooCommerceHmac::sign($this->secret, $this->body);
        self::assertTrue(WooCommerceHmac::verify($this->secret, $sig, $this->body));
    }

    public function test_wrong_secret_fails(): void
    {
        $sig = WooCommerceHmac::sign($this->secret, $this->body);
        self::assertFalse(WooCommerceHmac::verify('wrong_secret', $sig, $this->body));
    }

    public function test_tampered_body_fails(): void
    {
        $sig = WooCommerceHmac::sign($this->secret, $this->body);
        self::assertFalse(WooCommerceHmac::verify($this->secret, $sig, '{"id":99}'));
    }

    public function test_empty_signature_fails(): void
    {
        self::assertFalse(WooCommerceHmac::verify($this->secret, '', $this->body));
    }

    public function test_empty_secret_fails(): void
    {
        $sig = WooCommerceHmac::sign($this->secret, $this->body);
        self::assertFalse(WooCommerceHmac::verify('', $sig, $this->body));
    }

    public function test_signature_is_base64(): void
    {
        $sig = WooCommerceHmac::sign($this->secret, $this->body);
        self::assertNotFalse(base64_decode($sig, strict: true));
    }
}
