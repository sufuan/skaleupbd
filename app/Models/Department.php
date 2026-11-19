<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['business_id', 'name', 'head_user_id'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_members')->withTimestamps();
    }

    public function sops(): HasMany
    {
        return $this->hasMany(Sop::class);
    }
}
