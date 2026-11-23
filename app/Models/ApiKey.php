<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $guarded = [];

    // Explicit so date casts never need a live DB connection to resolve
    // the format (keeps pure unit tests of scope/revocation boot-free).
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['key_hash'];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Look up a key row from the raw key string.
     * Keys are stored as hash('sha256', $raw) platform-wide, so this is an
     * indexed lookup - never a per-row verify scan. Returns null when no
     * matching, non-revoked key exists. Updates last_used_at as a side effect.
     */
    public static function findByRawKey(string $rawKey): ?self
    {
        $row = self::query()
            ->where('key_hash', hash('sha256', $rawKey))
            ->whereNull('revoked_at')
            ->first();

        if ($row) {
            $row->forceFill(['last_used_at' => now()])->save();
        }

        return $row;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Returns true when the key grants the requested scope.
     * A key with scopes = ["*"] grants everything; legacy rows with
     * scopes = NULL are treated as ["*"].
     * Valid scope names: orders.read, orders.write, products.read, products.write, stock.read.
     */
    public function grants(string $scope): bool
    {
        $scopes = $this->scopes ?? ['*'];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
