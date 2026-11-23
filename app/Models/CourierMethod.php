<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierMethod extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_preferred' => 'boolean',
        'charge_inside_dhaka' => 'decimal:2',
        'charge_outside_dhaka' => 'decimal:2',
    ];

    public const TYPES = ['PATHAO', 'STEADFAST', 'REDX', 'CARRYBEE', 'PAPERFLY', 'ECOURIER', 'BAHOK', 'OTHERS'];

    public const LABELS = [
        'PATHAO' => 'Pathao', 'STEADFAST' => 'Steadfast', 'REDX' => 'RedX', 'CARRYBEE' => 'Carrybee',
        'PAPERFLY' => 'Paperfly', 'ECOURIER' => 'eCourier', 'BAHOK' => 'Bahok', 'OTHERS' => 'Manual (Other)',
    ];

    /** Courier class per plan 10.7c: A full API, B booking API, C manual. */
    public function courierClass(): string
    {
        return match ($this->courier_type) {
            'STEADFAST' => 'A',
            'PATHAO', 'REDX', 'CARRYBEE', 'ECOURIER', 'PAPERFLY', 'BAHOK' => 'B',
            default => 'C',
        };
    }

    /** Paperfly's booking API is unverified (brief §7) — it books by recorded CN, like a manual courier. */
    public function bookingMode(): string
    {
        return in_array($this->courier_type, ['OTHERS'], true) ? 'manual' : 'api';
    }

    /**
     * The credential key that authenticates this courier's webhook
     * events, or null when the courier has no verifiable webhook
     * (Bahok / manual couriers have no webhook route at all).
     */
    public function webhookCredentialKey(): ?string
    {
        return match ($this->courier_type) {
            'STEADFAST', 'REDX' => 'webhook_token',
            'PATHAO', 'CARRYBEE', 'PAPERFLY', 'ECOURIER' => 'webhook_secret',
            default => null,
        };
    }

    public function hasWebhookCredential(): bool
    {
        $key = $this->webhookCredentialKey();

        return $key !== null && filled(($this->credentials ?? [])[$key] ?? null);
    }

    /**
     * Pathao / Carrybee onboarding hands the webhook secret back to the
     * courier once, in the integration event's response header, so the
     * courier can store it. That echo is only allowed in a short window
     * after the seller saves or rotates the secret in Settings (the
     * moment they are pasting the webhook URL into the courier panel):
     * saving stamps credentials.webhook_integration_pending_at, the
     * first successful echo clears it, and after 24 hours it is stale
     * either way. Outside the window the integration event is answered
     * plainly and the secret never leaves the server.
     */
    public function webhookIntegrationPending(): bool
    {
        $at = ($this->credentials ?? [])['webhook_integration_pending_at'] ?? null;
        if (! is_string($at) || $at === '') {
            return false;
        }
        try {
            $when = \Carbon\Carbon::parse($at);
        } catch (\Throwable) {
            return false;
        }

        return $when->between(now()->subHours(24), now()->addMinutes(5));
    }

    public function clearWebhookIntegrationPending(): void
    {
        $creds = $this->credentials ?? [];
        if (! array_key_exists('webhook_integration_pending_at', $creds)) {
            return;
        }
        unset($creds['webhook_integration_pending_at']);
        $this->credentials = $creds;
        $this->save();
    }

    public function label(): string
    {
        return $this->name ?: (self::LABELS[$this->courier_type] ?? $this->courier_type);
    }

    public function chargeFor(?string $district): float
    {
        return (float) (strtolower(trim((string) $district)) === 'dhaka' ? $this->charge_inside_dhaka : $this->charge_outside_dhaka);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pickupStores(): HasMany
    {
        return $this->hasMany(CourierPickupStore::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CourierSettlement::class);
    }
}
