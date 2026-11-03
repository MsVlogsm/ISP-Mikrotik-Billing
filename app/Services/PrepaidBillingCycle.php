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

    /** Calculate a prepaid expiry from the resulting due/credit balance. */
    public static function expiryFromBalance(
        $connectionDate,
        $currentExpiry,
        float $balanceDue,
        float $monthlyRent,
        $referenceDate = null
    ): ?Carbon {
        if (! $connectionDate || $balanceDue > 0.009 || $monthlyRent <= 0) {
            return null;
        }

        // Zero due covers the current cycle. Each full monthly-rent credit
        // covers one additional cycle.
        $cycles = 1 + (int) floor((max(0, -$balanceDue) + 0.00001) / $monthlyRent);
        $timezone = config('app.timezone') ?: 'Asia/Dhaka';
        $today = Carbon::parse($referenceDate ?? now($timezone), $timezone)->startOfDay();
        $storedExpiry = $currentExpiry
            ? Carbon::parse($currentExpiry, $timezone)->startOfDay()
            : null;

        if ($storedExpiry && $storedExpiry->gt($today)) {
            $target = self::alignToConnectionDay($connectionDate, $storedExpiry)
                ->addMonthsNoOverflow($cycles - 1);
        } else {
            $target = self::nextExpiryDate($connectionDate, $today)
                ->addMonthsNoOverflow($cycles - 1);
        }

        return self::alignToConnectionDay($connectionDate, $target);
    }

    /** Calculate prepaid validity after applying one new payment to the balance. */
    public static function expiryAfterPayment(
        $connectionDate,
        $currentExpiry,
        float $currentDue,
        float $paymentAmount,
        float $monthlyRent,
        $referenceDate = null
    ): ?Carbon {
        if (! $connectionDate || $paymentAmount <= 0 || $monthlyRent <= 0) {
            return null;
        }

        $newDue = round($currentDue - $paymentAmount, 2);
        if ($newDue > 0.009) {
            return null;
        }

        $timezone = config('app.timezone') ?: 'Asia/Dhaka';
        $today = Carbon::parse($referenceDate ?? now($timezone), $timezone)->startOfDay();
        $storedExpiry = $currentExpiry
            ? Carbon::parse($currentExpiry, $timezone)->startOfDay()
            : null;

        $existingCycles = $currentDue <= 0.009
            ? 1 + (int) floor((max(0, -$currentDue) + 0.00001) / $monthlyRent)
            : 0;
        $paidCycles = 1 + (int) floor((max(0, -$newDue) + 0.00001) / $monthlyRent);
        $additionalCycles = max(0, $paidCycles - $existingCycles);

        if ($additionalCycles < 1) {
            return null;
        }

        if ($storedExpiry && $storedExpiry->gt($today)) {
            // Extend only by newly purchased cycles; existing credit is already
            // represented by the future expiry and must not be counted twice.
            $target = self::alignToConnectionDay($connectionDate, $storedExpiry)
                ->addMonthsNoOverflow($additionalCycles);
        } else {
            // Expired/today expiry: recover all paid cycles from the next
            // connection-day boundary because no future validity is represented.
            $target = self::nextExpiryDate($connectionDate, $today)
                ->addMonthsNoOverflow($paidCycles - 1);
        }

        return self::alignToConnectionDay($connectionDate, $target);
    }
}
