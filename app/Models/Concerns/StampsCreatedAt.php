<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For log-style tables ($timestamps = false) whose created_at column
 * carries a database CURRENT_TIMESTAMP default. Left to the database,
 * the value is filled in the DB server's own timezone — Asia/Dhaka on
 * the production MySQL host, UTC on SQLite — while every Eloquent
 * timestamp is written by PHP in the app timezone (UTC). The two clocks
 * disagree by hours, so one event could show two different times on the
 * same page (an order's shipped_at vs its own history rows). Stamp
 * created_at in PHP instead, like TokenLedger already does; the
 * database default remains only as a backstop for raw inserts.
 */
trait StampsCreatedAt
{
    protected static function bootStampsCreatedAt(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->created_at)) {
                $model->created_at = $model->freshTimestamp();
            }
        });
    }
}
