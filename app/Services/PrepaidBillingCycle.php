<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Keeps prepaid validity on the customer's original connection-day each month.
 * For months without that calendar day (for example, the 31st in February),
 * the month-end is used without losing the original anchor for later months.
 */
final class PrepaidBillingCycle
{
    public static function alignToConnectionDay($connectionDate, $cycleDate): Carbon
    {
        $timezone = config('app.timezone') ?: 'Asia/Dhaka';
        $connection = Carbon::parse($connectionDate, $timezone)->startOfDay();
        $cycle = Carbon::parse($cycleDate, $timezone)->startOfMonth();
        $cycle->day(min($connection->day, $cycle->daysInMonth));

        return $cycle->startOfDay();
    }

    /** Return the first connection-day expiry strictly after the reference date. */
    public static function nextExpiryDate($connectionDate, $afterDate): Carbon
    {
        $timezone = config('app.timezone') ?: 'Asia/Dhaka';
        $after = Carbon::parse($afterDate, $timezone)->startOfDay();
        $candidate = self::alignToConnectionDay($connectionDate, $after);

        if ($candidate->lte($after)) {
            $nextMonth = $after->copy()->addMonthNoOverflow()->startOfMonth();
            $candidate = self::alignToConnectionDay($connectionDate, $nextMonth);
        }

        return $candidate;
    }
}
