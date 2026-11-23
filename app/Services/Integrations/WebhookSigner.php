<?php

// Goes in: app/Services/Integrations/WebhookSigner.php (new file). Pure PHP.
// The same algorithm, in plain PHP, goes into the stage 8 copy-paste kit for receivers.

namespace App\Services\Integrations;

final class WebhookSigner
{
    public const TOLERANCE_SECONDS = 300;

    /** Lower-case hex HMAC-SHA256 of "{timestamp}.{rawBody}". */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public static function verify(
        string $secret,
        string $timestampHeader,
        string $signatureHeader,
        string $rawBody,
        ?int $now = null,
    ): bool {
        if ($secret === '' || ! ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        $now ??= time();
        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $rawBody), strtolower(trim($signatureHeader)));
    }
}
