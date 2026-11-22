<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $fillable = ['business_id', 'kind', 'title', 'body', 'link', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The one tenancy rule for the notification stream, used by the
     * shared props, the sidebar counts and the mark-read endpoints:
     * a shop user sees only their own shop's rows; a platform admin
     * sees only platform rows (business_id NULL), never a shop's rows;
     * anyone else (no shop, not a platform admin) sees nothing.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->is_platform_admin) {
            return $query->whereNull('business_id');
        }

        if ($user?->business_id) {
            return $query->where('business_id', $user->business_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
