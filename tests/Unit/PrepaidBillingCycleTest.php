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

    public function test_one_full_prepaid_payment_renews_expired_cycle(): void
    {
        $this->assertSame(
            '2026-12-03',
            PrepaidBillingCycle::expiryAfterPayment('2026-10-03', '2026-11-03', 500, 500, 500, '2026-11-03')->toDateString()
        );
    }

    public function test_multiple_month_prepaid_payment_extends_by_paid_cycles(): void
    {
        $this->assertSame(
            '2027-03-03',
            PrepaidBillingCycle::expiryAfterPayment('2026-10-03', '2026-11-03', 500, 2000, 500, '2026-11-03')->toDateString()
        );
    }

    public function test_existing_prepaid_credit_reconciles_to_paid_cycles(): void
    {
        $this->assertSame(
            '2027-03-03',
            PrepaidBillingCycle::expiryFromBalance('2026-10-03', '2026-11-03', -1500, 500, '2026-11-03')
                ?->toDateString()
        );
    }

    public function test_new_payment_is_added_to_existing_prepaid_credit(): void
    {
        $this->assertSame(
            '2027-04-03',
            PrepaidBillingCycle::expiryAfterPayment('2026-10-03', '2026-11-03', -1500, 500, 500, '2026-11-03')
                ?->toDateString()
        );
    }

    public function test_payment_extends_an_already_future_expiry(): void
    {
        $this->assertSame(
            '2027-01-03',
            PrepaidBillingCycle::expiryAfterPayment('2026-10-03', '2026-12-03', 500, 500, 500, '2026-11-03')
                ?->toDateString()
        );
    }

    public function test_repeated_prepaid_receipts_add_one_cycle_each_without_double_counting_credit(): void
    {
        $anchor = '2026-10-03';
        $reference = '2026-11-03';

        $expiry = PrepaidBillingCycle::expiryAfterPayment($anchor, '2026-11-03', 500, 500, 500, $reference);
        $this->assertSame('2026-12-03', $expiry->toDateString());

        $expiry = PrepaidBillingCycle::expiryAfterPayment($anchor, $expiry, 0, 500, 500, $reference);
        $this->assertSame('2027-01-03', $expiry->toDateString());

        $expiry = PrepaidBillingCycle::expiryAfterPayment($anchor, $expiry, -500, 500, 500, $reference);
        $this->assertSame('2027-02-03', $expiry->toDateString());

        $expiry = PrepaidBillingCycle::expiryAfterPayment($anchor, $expiry, -1000, 500, 500, $reference);
        $this->assertSame('2027-03-03', $expiry->toDateString());
    }

    public function test_partial_payment_that_does_not_clear_prepaid_due_grants_no_cycle(): void
    {
        $this->assertNull(
            PrepaidBillingCycle::expiryAfterPayment('2026-10-03', '2026-11-03', 500, 250, 500, '2026-11-03')
        );
    }
}
