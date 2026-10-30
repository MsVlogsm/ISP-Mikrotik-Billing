<?php

namespace App\Services;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Prevents prepaid auto-disable validity from being extended by admin/edit/bulk
 * writes. A forward date change is allowed only inside the same DB transaction
 * as a newly recorded, positive, paid collection.
 */
class PrepaidExpiryGuard
{
    private static int $paidRenewalDepth = 0;

    public function saveAfterPaidCollection(BillingInfo $billing): bool
    {
        if (! $this->isPrepaid($billing) || ! $this->isExpiryExtension($billing)) {
            return $billing->save();
        }

        if (DB::transactionLevel() < 1 || ! $this->hasFreshPaidCollection($billing)) {
            $this->reject($billing, 'A prepaid expiry can only be extended in the same transaction as a recorded payment.');
        }

        self::$paidRenewalDepth++;

        try {
            return $billing->save();
        } finally {
            self::$paidRenewalDepth = max(0, self::$paidRenewalDepth - 1);
        }
    }

    /** Called from BillingInfo::saving so normal model saves cannot bypass the rule. */
    public function assertCanSave(BillingInfo $billing): void
    {
        if (! $billing->exists) {
            return; // Initial customer creation establishes the first validity date.
        }

        $originalType = (string) $billing->getRawOriginal('billing_type');
        $currentType = (string) $billing->billing_type;
        $wasPrepaid = $originalType === 'prepaid';
        $isPrepaid = $currentType === 'prepaid';

        if ($wasPrepaid && (bool) $billing->getRawOriginal('auto_disable') && ! (bool) $billing->auto_disable) {
            $this->reject($billing, 'Automatic expiry cannot be disabled for an existing prepaid customer.');
        }

        if (! ($wasPrepaid || $isPrepaid) || ! $billing->isDirty('auto_disable_date')) {
            return;
        }

        if ($this->isExpiryExtension($billing) && self::$paidRenewalDepth < 1) {
            $this->reject($billing, 'Prepaid expiry cannot be extended from Customer Edit, admin tools or imports without a recorded payment. Use Payment Collection or Recharge.');
        }
    }

    private function isPrepaid(BillingInfo $billing): bool
    {
        return (string) $billing->billing_type === 'prepaid'
            || (string) $billing->getRawOriginal('billing_type') === 'prepaid';
    }

    private function isExpiryExtension(BillingInfo $billing): bool
    {
        $newValue = $billing->auto_disable_date;
        if (! $newValue) {
            return false;
        }

        $oldValue = $billing->getRawOriginal('auto_disable_date');
        if (! $oldValue) {
            return true;
        }

        return Carbon::parse($newValue)->startOfDay()->gt(Carbon::parse($oldValue)->startOfDay());
    }

    private function hasFreshPaidCollection(BillingInfo $billing): bool
    {
        $customerId = (string) $billing->customer_bill_unique_id;
        if ($customerId === '') {
            return false;
        }

        $query = CollectionSummary::query()
            ->where('customer_collection_unique_id', $customerId)
            ->where('payment_status', 'paid')
            ->where('collection_amount', '>', 0)
            ->where('created_at', '>=', now()->subMinutes(5));

        $originalUpdatedAt = $billing->getRawOriginal('updated_at');
        if ($originalUpdatedAt) {
            $query->where('created_at', '>=', Carbon::parse($originalUpdatedAt)->subSeconds(2));
        }

        return $query->exists();
    }

    private function reject(BillingInfo $billing, string $message): never
    {
        Log::warning('Blocked prepaid expiry/auto-disable change without a paid renewal.', [
            'customer_unique_id' => $billing->customer_bill_unique_id,
            'old_expiry' => $billing->getRawOriginal('auto_disable_date'),
            'requested_expiry' => $billing->auto_disable_date,
            'old_auto_disable' => $billing->getRawOriginal('auto_disable'),
            'requested_auto_disable' => $billing->auto_disable,
            'billing_type' => $billing->billing_type,
            'user_id' => auth()->id(),
        ]);

        throw ValidationException::withMessages([
            'auto_disable_date' => $message,
        ]);
    }
}
