<?php

// Goes in: tests/Unit/WebhookSignerTest.php (new file).

namespace Tests\Unit;

use App\Services\Integrations\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function test_signature_is_hmac_of_timestamp_dot_body(): void
    {
        $sig = WebhookSigner::sign(self::SECRET, 1700000000, '{"a":1}');
        $this->assertSame(hash_hmac('sha256', '1700000000.{"a":1}', self::SECRET), $sig);
        $this->assertSame(64, strlen($sig));
    }

    public function test_fresh_valid_signature_passes(): void
    {
        $now = 1700000000;
        $sig = WebhookSigner::sign(self::SECRET, $now, 'body');
        $this->assertTrue(WebhookSigner::verify(self::SECRET, (string) $now, $sig, 'body', $now));
        $this->assertTrue(WebhookSigner::verify(self::SECRET, (string) ($now - 300), WebhookSigner::sign(self::SECRET, $now - 300, 'body'), 'body', $now));
    }

    public function test_stale_or_future_timestamp_is_rejected(): void
    {
        $now = 1700000000;
        foreach ([$now - 301, $now + 301, $now - 86400] as $ts) {
            $sig = WebhookSigner::sign(self::SECRET, $ts, 'body');
            $this->assertFalse(WebhookSigner::verify(self::SECRET, (string) $ts, $sig, 'body', $now), "ts offset must be rejected");
        }
    }

    public function test_bad_signature_tampered_body_wrong_secret_and_junk_are_rejected(): void
    {
        $now = 1700000000;
        $sig = WebhookSigner::sign(self::SECRET, $now, 'body');

        $this->assertFalse(WebhookSigner::verify(self::SECRET, (string) $now, $sig, 'body2', $now));
        $this->assertFalse(WebhookSigner::verify('other', (string) $now, $sig, 'body', $now));
        $this->assertFalse(WebhookSigner::verify(self::SECRET, (string) $now, str_repeat('0', 64), 'body', $now));
        $this->assertFalse(WebhookSigner::verify(self::SECRET, 'abc', $sig, 'body', $now));
        $this->assertFalse(WebhookSigner::verify(self::SECRET, '', $sig, 'body', $now));
        $this->assertFalse(WebhookSigner::verify('', (string) $now, $sig, 'body', $now));
    }
}
