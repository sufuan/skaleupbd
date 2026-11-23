<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Audit trail for view-as-seller (ধাপ ৩): every time a platform admin
 * enters a shop read-only, leaves, is expired out, or has a write
 * blocked by the ViewAsReadOnly guard. Modelled on DeletionLog
 * (created_at only, stamped in PHP) with a RoleChangeLog-style
 * record() helper.
 */
class PlatformViewLog extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'detail' => 'array',
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

    public static function record(int $adminId, int $businessId, string $action, ?Request $request = null, array $detail = []): void
    {
        $route = $detail['route'] ?? null;
        unset($detail['route']);
        static::create([
            'admin_id' => $adminId,
            'business_id' => $businessId,
            'action' => $action,
            'route' => $route,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'detail' => $detail === [] ? null : $detail,
        ]);
    }
}
