<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for Staff & Roles changes (ধাপ ৪): role create, update,
 * delete and staff assignment changes, one row per change, scoped to
 * the shop. Kept in its own table (not settings_changes) because the
 * settings log has no business column and is platform-global.
 */
#[Fillable(['business_id', 'actor_id', 'action', 'detail'])]
class RoleChangeLog extends Model
{
    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }

    public static function record(int $businessId, ?int $actorId, string $action, array $detail = []): void
    {
        static::create([
            'business_id' => $businessId,
            'actor_id' => $actorId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }
}
