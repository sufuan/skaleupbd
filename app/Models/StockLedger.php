<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    use StampsCreatedAt;
    protected $table = 'stock_ledger';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
