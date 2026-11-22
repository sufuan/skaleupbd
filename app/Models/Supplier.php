<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = [];

    protected $casts = ['terms_days' => 'integer'];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    /** Two-letter avatar initials, as the Suppliers table shows them. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $letters .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return $letters ?: 'S';
    }
}
