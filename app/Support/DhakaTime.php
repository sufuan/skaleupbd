<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The app's one timezone rule. Stored datetimes are UTC instants
 * (written by PHP in the app timezone); sellers live in Asia/Dhaka, so
 * anything a human reads — a formatted time, a "today" window, a
 * calendar date — is the Dhaka wall-clock reading of that instant.
 * Business-date columns (finance txn_date and friends) already store
 * Dhaka calendar dates and never pass through here.
 */
class DhakaTime
{
    public const TZ = 'Asia/Dhaka';

    /** Format a stored instant as Dhaka wall-clock, or null. */
    public static function fmt(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $c = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);

        return $c->setTimezone(self::TZ)->format($format);
    }

    /** Today's calendar date in Dhaka (Y-m-d). */
    public static function today(): string
    {
        return Carbon::now(self::TZ)->toDateString();
    }

    /**
     * UTC bounds [from, to] covering one Dhaka calendar day — for
     * whereBetween() against stored UTC instants. (A plain whereDate()
     * compares the UTC date, which is the previous day during Dhaka
     * mornings.)
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBoundsUtc(string $dhakaDate): array
    {
        $start = Carbon::parse($dhakaDate, self::TZ)->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }
}
