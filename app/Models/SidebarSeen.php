<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-user sidebar badge watermark — see the sidebar_seen migration. */
class SidebarSeen extends Model
{
    protected $table = 'sidebar_seen';

    protected $guarded = [];

    protected $casts = [
        'seen_at' => 'datetime',
        'seen_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
