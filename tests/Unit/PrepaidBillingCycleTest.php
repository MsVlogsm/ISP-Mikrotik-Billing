<?php

namespace Tests\Unit;

use App\Services\PrepaidBillingCycle;
use Tests\TestCase;

class PrepaidBillingCycleTest extends TestCase
{
    public function test_next_expiry_uses_connection_day_instead_of_payment_day(): void
    {
        $this->assertSame(
            '2026-11-23',
            PrepaidBillingCycle::nextExpiryDate('2026-09-23', '2026-10-23')->toDateString()
        );
    }

    public function test_existing_expiry_is_aligned_within_its_original_month(): void
    {
        $this->assertSame(
            '2026-11-23',
            PrepaidBillingCycle::alignToConnectionDay('2026-09-23', '2026-11-22')->toDateString()
        );
    }

    public function test_month_end_clamping_does_not_drift_the_original_anchor(): void
    {
        $this->assertSame(
            '2026-02-28',
            PrepaidBillingCycle::alignToConnectionDay('2026-01-31', '2026-02-15')->toDateString()
        );
        $this->assertSame(
            '2026-03-31',
            PrepaidBillingCycle::nextExpiryDate('2026-01-31', '2026-02-28')->toDateString()
        );
    }

    public function test_payment_before_future_expiry_advances_to_next_anchored_cycle(): void
    {
        $currentExpiry = PrepaidBillingCycle::alignToConnectionDay('2026-09-23', '2026-11-22');
        $this->assertSame(
            '2026-12-23',
            PrepaidBillingCycle::nextExpiryDate('2026-09-23', $currentExpiry)->toDateString()
        );
    }
}
