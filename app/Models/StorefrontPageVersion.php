<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M18 — a published snapshot of a page (the last 5 are kept). */
class StorefrontPageVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['blocks' => 'array'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(StorefrontPage::class, 'page_id');
    }
}
