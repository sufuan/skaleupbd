<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M18 — one row per page per day: batched views + order count. */
class StorefrontDailyStat extends Model
{
    protected $guarded = [];

    protected $casts = ['date' => 'date'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(StorefrontPage::class, 'page_id');
    }
}
