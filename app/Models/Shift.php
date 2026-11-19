<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Shift extends Model
{
    protected $fillable = ['business_id', 'name', 'start_time', 'end_time'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shift_assignments')->withTimestamps();
    }
}
