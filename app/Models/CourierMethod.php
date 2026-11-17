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
        return in_array($this->courier_type, ['OTHERS', 'PAPERFLY'], true) ? 'manual' : 'api';
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
