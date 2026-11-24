<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

/**
 * Payment Gateway settings — ONE code path, two scopes.
 *
 * Platform scope (keys payment.platform.*): the platform's own
 * merchant accounts, edited by the platform admin — this is how
 * sellers pay US for points packs, and its enabled numbers are what
 * the Billing claim card shows.
 *
 * Seller scope (keys payment.{businessId}.*): each seller's OWN
 * merchant accounts for their shop's customer payments, shown on
 * their storefront (e.g. the advance-payment box on the order
 * success page). Scopes are resolved server-side from the
 * authenticated user only — a seller can never read or write the
 * platform scope or another seller's scope.
 *
 * Secrets (SSLCommerz store password, ShurjoPay password) are stored
 * encrypted with a plain 'set' marker, following the channel-secret
 * pattern; view() only ever exposes *_set booleans, never the secret.
 */
class PaymentGatewayService
{
    public const NUMBER_METHODS = [
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    private function prefix(?int $businessId): string
    {
        return $businessId === null ? 'payment.platform' : "payment.{$businessId}";
    }

    /**
     * Form props for one scope — secrets appear only as *_set flags.
     * In view-as-seller (ধাপ ৩, $redact) the payment numbers and
     * account ids are also hidden: only *_set flags remain, because a
     * read-only support view does not need the shop's numbers.
     */
    public function view(?int $businessId, bool $redact = false): array
    {
        $p = $this->prefix($businessId);
        $hide = fn (string $key) => $redact ? '' : (string) $this->settings->get("{$p}.{$key}", '');

        return [
            'bkash_number_set' => (string) $this->settings->get("{$p}.bkash_number", '') !== '',
            'bkash_number' => $hide('bkash_number'),
            'bkash_enabled' => (bool) $this->settings->get("{$p}.bkash_enabled", false),
            'nagad_number_set' => (string) $this->settings->get("{$p}.nagad_number", '') !== '',
            'nagad_number' => $hide('nagad_number'),
            'nagad_enabled' => (bool) $this->settings->get("{$p}.nagad_enabled", false),
            'rocket_number_set' => (string) $this->settings->get("{$p}.rocket_number", '') !== '',
            'rocket_number' => $hide('rocket_number'),
            'rocket_enabled' => (bool) $this->settings->get("{$p}.rocket_enabled", false),
            'ssl_store_id_set' => (string) $this->settings->get("{$p}.ssl_store_id", '') !== '',
            'ssl_store_id' => $hide('ssl_store_id'),
            'ssl_enabled' => (bool) $this->settings->get("{$p}.ssl_enabled", false),
            'ssl_password_set' => (string) $this->settings->get("{$p}.ssl_password_enc", '') !== '',
            'shurjo_username_set' => (string) $this->settings->get("{$p}.shurjo_username", '') !== '',
            'shurjo_username' => $hide('shurjo_username'),
            'shurjo_enabled' => (bool) $this->settings->get("{$p}.shurjo_enabled", false),
            'shurjo_password_set' => (string) $this->settings->get("{$p}.shurjo_password_enc", '') !== '',
            'instructions' => (string) $this->settings->get("{$p}.instructions", ''),
        ];
    }

    /** Save one scope. Blank secret fields keep the stored secret. Every write goes through the logged setter so the Settings Change History covers gateway saves too (Hunt W W3). */
    public function save(?int $businessId, array $data, ?\App\Models\User $actor = null): void
    {
        $p = $this->prefix($businessId);
        $plain = [
            'bkash_number', 'bkash_enabled', 'nagad_number', 'nagad_enabled',
            'rocket_number', 'rocket_enabled', 'ssl_store_id', 'ssl_enabled',
            'shurjo_username', 'shurjo_enabled', 'instructions',
        ];
        foreach ($plain as $field) {
            if (array_key_exists($field, $data)) {
                if (is_string($data[$field])) {
                    $this->forceStringType("{$p}.{$field}");
                }
                $this->settings->setLogged("{$p}.{$field}", $data[$field], $actor);
            }
        }
        if (! empty($data['ssl_password'])) {
            $this->settings->setLogged("{$p}.ssl_password_enc", Crypt::encryptString((string) $data['ssl_password']), $actor);
            $this->settings->setLogged("{$p}.ssl_password", 'set', $actor);
        }
        if (! empty($data['shurjo_password'])) {
            $this->settings->setLogged("{$p}.shurjo_password_enc", Crypt::encryptString((string) $data['shurjo_password']), $actor);
            $this->settings->setLogged("{$p}.shurjo_password", 'set', $actor);
        }
    }

    /**
     * Enabled manual-payment numbers for display (bKash / Nagad /
     * Rocket). SSLCommerz and ShurjoPay are redirect gateways and
     * have no number to display. Returns [['method','label','number']].
     */
    public function enabledNumbers(?int $businessId): array
    {
        $p = $this->prefix($businessId);
        $out = [];
        foreach (self::NUMBER_METHODS as $key => $label) {
            $number = trim((string) $this->settings->get("{$p}.{$key}_number", ''));
            if ($number !== '' && (bool) $this->settings->get("{$p}.{$key}_enabled", false)) {
                $out[] = ['method' => $key, 'label' => $label, 'number' => $number];
            }
        }

        return $out;
    }

    /**
     * Phone numbers and IDs are TEXT: SettingsService infers a
     * 'number' type for numeric strings and its decoder casts back
     * with +0, which would eat a leading zero (017… → 171…). Pin
     * these keys to the string type so values round-trip exactly.
     */
    private function forceStringType(string $key): void
    {
        $row = \App\Models\Setting::where('key', $key)->first();
        if ($row && $row->type !== 'string') {
            $row->update(['type' => 'string']);
            \Illuminate\Support\Facades\Cache::forget('settings.'.$key);
        } elseif (! $row) {
            \App\Models\Setting::create([
                'key' => $key, 'value' => '', 'type' => 'string',
                'group' => strstr($key, '.', true) ?: 'general',
            ]);
        }
    }

    /** Decrypt a stored secret for server-side use only. Never props. */
    public function secret(?int $businessId, string $which): ?string
    {
        $enc = (string) $this->settings->get($this->prefix($businessId).".{$which}_password_enc", '');
        if ($enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }
}
