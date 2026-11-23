<?php

// Goes in: app/Services/Integrations/WooCommerceHmac.php (new file).
// Pure PHP — no framework dependency.
//
// WooCommerce signs webhook payloads with HMAC-SHA256 of the raw body,
// then base64-encodes the result, and sends it in X-WC-Webhook-Signature.
// (verify) Confirm the exact header name and encoding from live WooCommerce docs before first use.
//
// This is intentionally separate from WebhookSigner (which uses our outbound format:
// timestamp.body). The two algorithms must NOT be mixed up.

namespace App\Services\Integrations;

final class WooCommerceHmac
{
    /**
     * Verify a WooCommerce inbound webhook signature.
     *
     * @param  string  $secret         our webhook_secret_enc stored for this connection
     * @param  string  $signatureB64   value of X-WC-Webhook-Signature header (base64)
     * @param  string  $rawBody        the raw request body
     */
    public static function verify(string $secret, string $signatureB64, string $rawBody): bool
    {
        if ($secret === '' || $signatureB64 === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signatureB64);
    }

    /**
     * Compute the signature for a given body and secret (useful in tests).
     */
    public static function sign(string $secret, string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }
}
