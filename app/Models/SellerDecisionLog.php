<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail of platform decisions on sellers (Round 9,
 * Hunt Y4 F10): who approved or rejected a shop, when, and the note
 * given. Modelled on PlatformViewLog (created_at only, stamped in
 * PHP). A rejection row survives a later re-approval - it is history,
 * never overwritten.
 */
class SellerDecisionLog extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public static function record(int $businessId, ?int $adminId, string $action, ?string $note = null): void
    {
        static::create([
            'business_id' => $businessId,
            'admin_id' => $adminId,
            'action' => $action,
            'note' => $note,
        ]);
    }
}
