<?php

namespace Tests\Unit;

use App\Models\BillingInfo;
use App\Services\PrepaidExpiryGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrepaidExpiryGuardTest extends TestCase
{
    private function existingPrepaidBilling(array $overrides = []): BillingInfo
    {
        $billing = new BillingInfo();
        $billing->exists = true;
        $billing->setRawAttributes(array_merge([
            'id' => 10,
            'customer_bill_unique_id' => 'BT-EXPIRY-GUARD-TEST',
            'billing_type' => 'prepaid',
            'auto_disable' => 1,
            'auto_disable_date' => '2026-10-29 00:00:00',
            'updated_at' => '2026-10-20 10:00:00',
        ], $overrides), true);

        return $billing;
    }

    public function test_payment_free_prepaid_expiry_extension_is_blocked(): void
    {
        $billing = $this->existingPrepaidBilling();
        $billing->auto_disable_date = '2026-11-29';

        $this->expectException(ValidationException::class);
        app(PrepaidExpiryGuard::class)->assertCanSave($billing);
    }

    public function test_billing_info_saving_event_blocks_unpaid_expiry_extension(): void
    {
        $billing = $this->existingPrepaidBilling();
        $billing->auto_disable_date = '2026-11-29';

        // The model's saving hook rejects this before Eloquent attempts a DB update.
        $this->expectException(ValidationException::class);
        $billing->save();
    }

    public function test_prepaid_expiry_can_be_shortened_or_left_unchanged(): void
    {
        $guard = app(PrepaidExpiryGuard::class);
        $earlier = $this->existingPrepaidBilling();
        $earlier->auto_disable_date = '2026-10-25';
        $guard->assertCanSave($earlier);

        $same = $this->existingPrepaidBilling();
        $same->auto_disable_date = '2026-10-29';
        $guard->assertCanSave($same);

        $this->assertTrue(true);
    }

    public function test_existing_prepaid_auto_disable_cannot_be_switched_off(): void
    {
        $billing = $this->existingPrepaidBilling();
        $billing->auto_disable = false;

        $this->expectException(ValidationException::class);
        $billing->save();
    }

    public function test_payment_service_rejects_extension_outside_a_transaction(): void
    {
        $billing = $this->existingPrepaidBilling();
        $billing->auto_disable_date = '2026-11-29';

        $this->expectException(ValidationException::class);
        app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($billing);
    }

    public function test_postpaid_expiry_rules_are_not_changed_by_prepaid_guard(): void
    {
        $billing = $this->existingPrepaidBilling(['billing_type' => 'postpaid']);
        $billing->auto_disable_date = '2026-11-29';

        app(PrepaidExpiryGuard::class)->assertCanSave($billing);
        $this->assertTrue(true);
    }
}
