<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointPack extends Model
{
    protected $guarded = [];

    protected $casts = ['price_bdt' => 'float', 'credits' => 'integer', 'is_active' => 'boolean'];

    /** Derived per-order rate in BDT (price ÷ credits). Never stored. */
    public function rate(): float
    {
        return $this->credits > 0 ? (float) $this->price_bdt / $this->credits : 0.0;
    }
}
