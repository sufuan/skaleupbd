<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Amar SMS gateway driver (amarsms.com).
 *
 * The provider's endpoint paths, parameter names and auth style are NOT yet
 * confirmed — Amar SMS publishes them behind the portal login at
 * portal.amarsms.com/docs, available after account approval. They live in
 * ONE place: config/sms.php under 'amarsms' (base_url, send_path,
 * balance_path, auth). Until send_path is filled in there, this driver is
 * deliberately inert: every send returns ok=false with
 * error='Gateway not configured' rather than guessing an endpoint.
 *
 * All calls go through Laravel's Http client so engine checks can fake them.
 */
class AmarSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $senderId = null,
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("sms.amarsms.{$key}", $default);
    }

    public function configured(): bool
    {
        return $this->apiKey !== ''
            && (string) $this->cfg('base_url', '') !== ''
            && (string) $this->cfg('send_path', '') !== '';
    }

    public function send(string $to, string $body, ?string $senderId = null): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'provider_message_id' => null, 'error' => 'Gateway not configured', 'response' => null];
        }

        try {
            $response = $this->request()->post($this->url('send_path'), [
                // Parameter names to be confirmed against portal.amarsms.com/docs.
                'to' => $to,
                'message' => $body,
                'sender_id' => $senderId ?? $this->senderId,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'provider_message_id' => null, 'error' => 'Gateway request failed: ' . $e->getMessage(), 'response' => null];
        }

        $json = $response->json();
        $status = is_array($json) ? ($json['status'] ?? $json['data']['status'] ?? null) : null;
        $ok = $response->successful()
            && ($status === null || in_array(strtolower((string) $status), ['success', 'ok', 'sent', 'submitted'], true));
        $messageId = is_array($json)
            ? ($json['message_id'] ?? $json['data']['message_id'] ?? $json['id'] ?? $json['data']['id'] ?? null)
            : null;
        $error = $ok ? null : ((is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null) ?? ('Gateway answered HTTP ' . $response->status()));

        return [
            'ok' => $ok,
            'provider_message_id' => $messageId !== null ? (string) $messageId : null,
            'error' => $error,
            'response' => is_array($json) ? $json : $response->body(),
        ];
    }

    public function balance(): ?float
    {
        if ($this->apiKey === '' || (string) $this->cfg('balance_path', '') === '') {
            return null;
        }

        try {
            $response = $this->request()->get($this->url('balance_path'));
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }
        $value = $json['balance'] ?? $json['data']['balance'] ?? $json['credit'] ?? $json['data']['credit'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function url(string $pathKey): string
    {
        return rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim((string) $this->cfg($pathKey), '/');
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(20)->acceptJson();

        // Auth style per config/sms.php 'auth' — confirm against the portal docs.
        return match ((string) $this->cfg('auth', 'bearer')) {
            'header' => $request->withHeaders(['X-API-Key' => $this->apiKey]),
            default => $request->withToken($this->apiKey),
        };
    }
}
