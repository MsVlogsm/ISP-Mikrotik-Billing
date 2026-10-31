<?php

namespace App\Services;

use App\Events\PackagePurchased;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\SMSController;
use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\PaymentSummary;
use App\Models\PPPSecrets;
use App\Services\PrepaidExpiryGuard;
use App\Services\PrepaidBillingCycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class PaymentService
{
    /**
     * Process a successful online payment, update billing records,
     * record collection summary, and activate the user on MikroTik.
     */
    public function processSuccessPayment(CustomersInfo $customer, float $amount, string $gateway, string $trxId, array $metadata = []): bool
    {
        $trxId = trim($trxId);
        if ($trxId === '' || $amount <= 0) {
            throw new \InvalidArgumentException('Payment transaction ID and amount must be valid.');
        }

        if (CollectionSummary::where('transaction_id', $trxId)
            ->where('payment_status', 'paid')
            ->exists()) {
            Log::info("Payment already processed for transaction {$trxId}");
            return false;
        }

        DB::beginTransaction();
        try {
            $billing = $customer->billing;
            if (! $billing) {
                throw new \Exception("Billing information not found for customer {$customer->customer_unique_id}");
            }

            // Fetch reseller of the voucher if it was voucher recharge, or customer's reseller
            $reseller = null;
            if ($gateway === 'voucher') {
                $voucher = \App\Models\Voucher::where('code', $trxId)->first();
                if ($voucher) {
                    $reseller = $voucher->reseller;
                }
            }
            if (! $reseller && $customer->reseller_id) {
                $reseller = $customer->reseller;
            }

            $useProrated = $reseller ? (bool)$reseller->getSetting('use_prorated_validity', true) : true;
            $allowPartialActivation = $reseller ? (bool)$reseller->getSetting('allow_partial_activation', false) : false;

            $isProratedApplied = false;
            $rent = (float) $billing->monthly_rent ?: 1.00;
            $currentDue = (float) $billing->due_amount;

            if ($useProrated && $amount < $rent && $amount > 0) {
                // Prorated daily validity calculation
                $proratedDays = (int) round(($amount / $rent) * 30);
                if ($proratedDays < 1) {
                    $proratedDays = 1;
                }

                $baseDate = today();
                if ($billing->auto_disable_date) {
                    $currentExpire = Carbon::parse($billing->auto_disable_date)->startOfDay();
                    if ($currentExpire->gt(today())) {
                        $baseDate = $currentExpire;
                    }
                }

                $expireDate = $baseDate->addDays($proratedDays)->format('Y-m-d');
                $newDue = 0.00;
                $advancePaid = 0.00;
                $isProratedApplied = true;
            } else {
                // Standard monthly billing calculations
                $newDue = max(0.00, $currentDue - $amount);
                $advancePaid = max(0.00, $amount - $currentDue);
                $expireDate = $billing->auto_disable_date;

                if ($newDue > 0) {
                    $extra_month = floor($newDue / $rent);
                    if ($extra_month < $billing->auto_disable_month) {
                        $expireDate = Carbon::parse($billing->auto_disable_date)
                            ->month(now()->month)->year(now()->year)
                            ->subMonths($extra_month)
                            ->format('Y-m-d');
                    } else {
                        $expireDate = Carbon::parse($billing->auto_disable_date)
                            ->month(now()->month)->year(now()->year)
                            ->subMonths($billing->auto_disable_month)
                            ->addMonths(1)
                            ->format('Y-m-d');
                    }
                } elseif ($advancePaid > 0) {
                    $extra_month = 1 + floor($advancePaid / $rent);
                    $expireDate = Carbon::parse($billing->auto_disable_date)
                        ->month(now()->month)->year(now()->year)
                        ->addMonths($extra_month)
                        ->format('Y-m-d');
                } else {
                    $expireDate = Carbon::parse($billing->auto_disable_date)
                        ->month(now()->month)->year(now()->year)
                        ->addMonths(1)
                        ->format('Y-m-d');
                }
            }

            $prepaidRentPaid = $billing->billing_type === 'prepaid'
                && (float) $billing->monthly_rent > 0
                && $amount >= (float) $billing->monthly_rent;

            // Prepaid expiry follows the connection-day anchor while retaining existing
            // payment allocation, advance-month, and prorated-validity rules.
            if ($billing->billing_type === 'prepaid' && ! $isProratedApplied) {
                $cycleAnchor = $customer->connection_date ?: ($billing->auto_disable_date ?: today()->toDateString());
                $expiryToAlign = $expireDate ?: ($billing->auto_disable_date ?: today()->toDateString());
                $alignedExpiry = PrepaidBillingCycle::alignToConnectionDay($cycleAnchor, $expiryToAlign);

                // Never reactivate a prepaid customer with an expiry still in the past.
                // Preserve old-due accounting, but start the paid validity at the next connection day.
                if (($newDue <= 0 || $allowPartialActivation || $prepaidRentPaid) && $alignedExpiry->lte(today())) {
                    $expireDate = PrepaidBillingCycle::nextExpiryDate($cycleAnchor, today())->toDateString();
                } else {
                    $expireDate = $alignedExpiry->toDateString();
                }
            }

            // Use the same transaction-safe invoice sequence as manual collections.
            $invoiceNo = CollectionSummary::nextInvoiceNumber();

            // 2. Create Collection Record
            $paymentDetails = array_filter([
                'source' => $metadata['source'] ?? null,
                'external_invoice_id' => $metadata['external_invoice_id'] ?? null,
                'notes' => $metadata['notes'] ?? null,
                'api_token_id' => $metadata['api_token_id'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');

            CollectionSummary::create([
                'customer_collection_unique_id' => $customer->customer_unique_id,
                'collection_date' => Carbon::now(),
                'collection_amount' => $amount,
                'collected_by' => 'Online Payment ('.strtoupper($gateway).')',
                'payment_type' => 'online',
                'payment_method' => $gateway,
                'transaction_id' => $trxId,
                'details' => $paymentDetails ? json_encode($paymentDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'payment_status' => 'paid',
                'invoice_no' => $invoiceNo,
                'bill_month' => Carbon::now()->format('F Y'),
            ]);

            // 3. Update BillingInfo only after the paid collection row exists.
            $billing->paid_amount = $billing->paid_amount + $amount;
            $billing->paid_date = Carbon::now();
            $billing->auto_disable_date = $expireDate;
            $billing->due_amount = $newDue;
            app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($billing);

            // 4. Create Monthly Payment Summary if not exists for the current month
            $summaryExists = PaymentSummary::where('customer_payment_unique_id', $customer->customer_unique_id)
                ->where('summary_date', Carbon::now()->firstOfMonth()->format('Y-m-d'))
                ->exists();

            if (! $summaryExists) {
                PaymentSummary::create([
                    'customer_payment_unique_id' => $customer->customer_unique_id,
                    'summary_date' => Carbon::now()->firstOfMonth()->format('Y-m-d'),
                    'monthly_rent' => $billing->monthly_rent,
                    'additional_charge' => $billing->additional_charge,
                    'vat' => $billing->vat,
                    'previous_due' => $billing->previous_due,
                    'advance' => $billing->advance,
                    'discount' => $billing->discount,
                ]);
            }

            // 5. Update customer status based on due and partial activation setting
            if ($newDue == 0 || $allowPartialActivation || $isProratedApplied || $prepaidRentPaid) {
                $customer->status = 'active';
                $customer->disable_count = 0;
            } else {
                $customer->status = 'inactive';
            }
            $customer->save();

            // 6. Extend auto_disable_date if today is beyond it
            if ($billing->billing_type !== 'prepaid' && ! $isProratedApplied && $billing->auto_disable_date) {
                $autoDisableDate = Carbon::parse($billing->auto_disable_date)->startOfDay();
                $autoDisableMonth = $billing->auto_disable_month;
                $disableDate = $autoDisableDate->copy()->addMonths($autoDisableMonth);

                if ($disableDate->lte(today())) {
                    while ($disableDate->lte(today())) {
                        $disableDate->addMonth();
                    }

                    $billing->auto_disable_date = $disableDate->copy()->subMonths($autoDisableMonth)->toDateString();
                    $billing->save();
                }
            }

            // 7. Sync the PPP account after billing state is updated.
            // A confirmed gateway payment must not be rolled back just because the
            // router is temporarily unreachable; queue an idempotent retry instead.
            if ($customer->pppUser) {
                $routerName = $customer->pppUser->router_name;
                $username = $customer->pppUser->username;

                if (filled($routerName) && filled($username)) {
                    if ($customer->status === 'active') {
                        try {
                            app(MikrotikController::class)->enablePPPSecret(
                                $customer->customer_unique_id,
                                $routerName,
                                $username
                            );

                            if (filled($customer->pppUser->profile)) {
                                app(MikrotikController::class)->updatePPPSecret(
                                    $routerName,
                                    $username,
                                    'profile',
                                    $customer->pppUser->profile
                                );
                            }

                            try {
                                app(MikrotikController::class)->singleWrite(
                                    $routerName,
                                    '/ppp active remove [find name="'.$username.'"]'
                                );
                            } catch (\Throwable $e) {
                                Log::debug('PaymentService session kick skipped: '.$e->getMessage());
                            }

                            PPPSecrets::where('id', $customer->ppp_user_id)->update(['status' => 'active']);
                        } catch (\Throwable $routerError) {
                            Log::warning('Online payment recorded; MikroTik activation queued after router failure.', [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'router' => $routerName,
                                'username' => $username,
                                'error' => $routerError->getMessage(),
                            ]);
                            try {
                                app(MikrotikPendingActionQueue::class)->queue(
                                    $customer->customer_unique_id,
                                    $routerName,
                                    $username,
                                    'enable'
                                );
                            } catch (\Throwable $queueError) {
                                Log::critical('Unable to queue MikroTik activation after successful online payment.', [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'router' => $routerName,
                                    'username' => $username,
                                    'error' => $queueError->getMessage(),
                                ]);
                            }
                            PPPSecrets::where('id', $customer->ppp_user_id)->update(['status' => 'active']);
                        }
                    } else {
                        try {
                            app(MikrotikController::class)->disablePPPSecret(
                                $customer->customer_unique_id,
                                $routerName,
                                $username,
                                false,
                                true
                            );
                        } catch (\Throwable $routerError) {
                            Log::warning('MikroTik disable queued after partial-payment decision.', [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'router' => $routerName,
                                'username' => $username,
                                'error' => $routerError->getMessage(),
                            ]);
                            try {
                                app(MikrotikPendingActionQueue::class)->queue(
                                    $customer->customer_unique_id,
                                    $routerName,
                                    $username,
                                    'disable'
                                );
                            } catch (\Throwable $queueError) {
                                Log::critical('Unable to queue MikroTik disable action.', [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'router' => $routerName,
                                    'username' => $username,
                                    'error' => $queueError->getMessage(),
                                ]);
                            }
                        }
                        PPPSecrets::where('id', $customer->ppp_user_id)->update(['status' => 'disabled']);
                    }
                } else {
                    Log::warning('Payment processed but PPP activation skipped because router/user mapping is incomplete.', [
                        'customer_unique_id' => $customer->customer_unique_id,
                        'router' => $routerName,
                        'username' => $username,
                    ]);
                }
            }

            DB::commit();

            // Fire reseller commission event
            event(new PackagePurchased($customer, $amount));

            // 8. Send SMS confirmation
            try {
                $smsData = [
                    'recipient' => $customer->mobile,
                    'customer_name' => $customer->customer_name,
                    'collection_amount' => $amount,
                    'ip_or_user_name' => $customer->pppUser->username ?? '',
                    'due_amount' => $newDue,
                    'company_name' => siteUrlSettings('site_name') ?: config('app.name'),
                ];
                $smsResponse = app(SMSController::class)->paymentCollectionSMS($smsData);
                if ($smsResponse && ! $smsResponse->isSuccessful()) {
                    Log::warning("SMS Confirmation not delivered for {$customer->customer_unique_id}: ".$smsResponse->getMessage());
                }
            } catch (\Exception $smsEx) {
                Log::error("SMS Confirmation failed for {$customer->customer_unique_id}: ".$smsEx->getMessage());
            }

            return true;

        } catch (QueryException $e) {
            DB::rollBack();
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'transaction_id') && (str_contains($message, 'unique') || str_contains($message, 'duplicate'))) {
                Log::info("Concurrent duplicate payment ignored for transaction {$trxId}");
                return false;
            }
            Log::error('PaymentService database processing failed: '.$e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PaymentService processing failed: '.$e->getMessage());
            throw $e;
        }
    }
}
