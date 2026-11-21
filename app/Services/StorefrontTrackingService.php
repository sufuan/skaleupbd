<?php

namespace App\Services;

use App\Models\Storefront;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * M18 — Meta Conversions API sender. Browser pixel events and these
 * server events share the same event_id, so Meta dedupes them. Every
 * call is wrapped: tracking must never break an order.
 */
class StorefrontTrackingService
{
    public function capiToken(Storefront $sf): ?string
    {
        $enc = (string) ($sf->branding['capi_token_enc'] ?? '');
        if ($enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function pixelId(Storefront $sf): ?string
    {
        $id = trim((string) ($sf->branding['pixel_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * Fire one server event. $eventId MUST be the id the browser used
     * (or will use) for the same event.
     */
    public function send(Storefront $sf, string $eventName, string $eventId, array $customData = [], ?string $sourceUrl = null, ?string $clientIp = null, ?string $userAgent = null): bool
    {
        try {
            $pixel = $this->pixelId($sf);
            $token = $this->capiToken($sf);
            if (! $pixel || ! $token) {
                return false;
            }
            $payload = [
                'data' => [[
                    'event_name' => $eventName,
                    'event_time' => time(),
                    'event_id' => $eventId,
                    'action_source' => 'website',
                    'event_source_url' => $sourceUrl,
                    'user_data' => array_filter([
                        'client_ip_address' => $clientIp,
                        'client_user_agent' => $userAgent,
                    ]),
                    'custom_data' => $customData,
                ]],
            ];
            $res = Http::timeout(6)->post("https://graph.facebook.com/v21.0/{$pixel}/events?access_token=".urlencode($token), $payload);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Storefront CAPI event failed', ['event' => $eventName, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
