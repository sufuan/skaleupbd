<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Nishchit gateway driver (nishchit.tech) — built entirely from the
 * provider's public docs and OpenAPI spec:
 *   https://nishchit.tech/docs/quickstart
 *   https://nishchit.tech/docs/send-an-sms
 *   https://nishchit.tech/docs/webhooks
 *   https://nishchit.tech/openapi.json
 *
 * Send:    POST /v1/messages, Authorization: Bearer <nk_test_…|nk_live_…>,
 *          JSON {to (E.164), body}. 201 returns the message object
 *          {id: msg_…, status: accepted|sending|sent|failed, …}.
 * Balance: GET /v1/balance → {object: "balance", bdt: "5.00", …} — the
 *          taka value is the `bdt` string (credits are segments, not taka).
 * Errors:  {error: {code, message, detail, doc_url, request_id}} — the
 *          stable `code` is documented, the message is what we log.
 *
 * Sender: v1 ships Nishchit-managed shared senders only — customer-owned
 * sender IDs are not available yet, and a `from` value that is not one of
 * the project's own senders is rejected with invalid_sender. So the
 * senderId argument is deliberately NOT passed as `from`; the account's
 * default shared sender is used, exactly as the docs recommend.
 *
 * The recipient arrives in the app's canonical 01XXXXXXXXX form and is
 * converted to the E.164 form the API requires (+8801…).
 *
 * All calls go through Laravel's Http client so engine checks can fake them.
 */
class NishchitGateway implements SmsGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $senderId = null,
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("sms.nishchit.{$key}", $default);
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
            // The docs recommend an Idempotency-Key on every POST and a
            // 15-second client timeout (their own send deadline is 10s).
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->post($this->url('send_path'), [
                    'to' => $this->e164($to),
                    'body' => $body,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'provider_message_id' => null, 'error' => 'Gateway request failed: ' . $e->getMessage(), 'response' => null];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'provider_message_id' => null, 'error' => 'Gateway answered HTTP ' . $response->status(), 'response' => $response->body()];
        }

        // A message object carries an `id`; a bare error envelope does not.
        if (empty($json['id'])) {
            // Error envelope: {error: {code, message, detail, …}}.
            $err = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
            $message = $err['message'] ?? $err['code'] ?? ('Gateway answered HTTP ' . $response->status());

            return ['ok' => false, 'provider_message_id' => null, 'error' => (string) $message, 'response' => $json];
        }

        // Message object: a failed message still has an id, and its own
        // MessageError {code, message, retryable} sits in `error`.
        $status = (string) ($json['status'] ?? '');
        $msgError = isset($json['error']) && is_array($json['error']) ? ($json['error']['message'] ?? null) : null;
        $ok = $response->successful() && $status !== 'failed' && ! empty($json['id']);

        return [
            'ok' => $ok,
            'provider_message_id' => ! empty($json['id']) ? (string) $json['id'] : null,
            'error' => $ok ? null : ($msgError ?? ('Gateway answered HTTP ' . $response->status())),
            'response' => $json,
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
        // `bdt` is the balance in taka ("5.00"); `credits` counts segments.
        $value = $json['bdt'] ?? $json['credit_value_bdt'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** Canonical app form 01XXXXXXXXX → E.164 +8801XXXXXXXXX. */
    private function e164(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($phone, '+') && str_starts_with($d, '880')) {
            return '+' . $d;
        }
        if (str_starts_with($d, '880')) {
            return '+' . $d;
        }
        if (str_starts_with($d, '0')) {
            return '+880' . substr($d, 1);
        }
        if (strlen($d) === 10 && str_starts_with($d, '1')) {
            return '+880' . $d;
        }

        return $phone;
    }

    private function url(string $pathKey): string
    {
        return rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim((string) $this->cfg($pathKey), '/');
    }

    private function request(): PendingRequest
    {
        // Documented auth: Authorization: Bearer <api key>.
        return Http::timeout(15)->acceptJson()->withToken($this->apiKey);
    }
}
