<?php

namespace App\Services\Sms;

/**
 * One SMS provider account (a seller's own). Implementations never throw:
 * failures come back as ok=false with a plain error string for the log row.
 */
interface SmsGateway
{
    /**
     * @return array{ok: bool, provider_message_id: ?string, error: ?string, response: mixed}
     */
    public function send(string $to, string $body, ?string $senderId = null): array;

    /** The provider account balance in taka, or null when it cannot be read. */
    public function balance(): ?float;

    /** True when the driver holds a key and its endpoints are configured. */
    public function configured(): bool;
}
