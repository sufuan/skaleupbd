<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M18 — wizard progress for a seller who has no store yet. */
class StorefrontOnboarding extends Model
{
    protected $table = 'storefront_onboarding';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
