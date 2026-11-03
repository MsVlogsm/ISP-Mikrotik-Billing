<?php

namespace App\Livewire;

use App\Http\Controllers\CustomersController;
use App\Http\Controllers\SMSController;
use App\Models\BillingInfo;
use App\Services\PrepaidExpiryGuard;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Services\PrepaidBillingCycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentCollection extends Component
{
    use WithPagination;

    public $user_list;

    public $customer_list = '';

    public ?CustomersInfo $info_data = null;

    public $collectionSummary = [];

    public $highlightedIndex = 0;

    public $customers = [];

    public $limit = 10;

    // In your Livewire component
    public $total_amount = 0;

    public $paid_amount;

    public $due_amount = '';

    public $expire_date = '';

    public $advance_paid = 0;

    public $payment_method = 'cash';

    public $transaction_id = '';

    public $payment_details = '';

    public $collection_date = '';

    public $send_sms = true;

    /** Stable across retries of one Livewire payment request. */
    public $payment_attempt_key = '';

    public function mount()
    {
        $this->authorizePaymentCollection();
        $this->payment_attempt_key = (string) Str::uuid();

        return true;
    }

    private function authorizePaymentCollection(): void
    {
        abort_unless(hasAccess(['Super Admin'], ['payment-collection']), 403, 'You do not have permission to collect payments.');
    }
    
    /**
     * Returns a CustomersInfo query builder scoped to the reseller's
     * own customers when the logged-in user is a Reseller, otherwise
     * returns an unscoped query for admins.
     */
    private function resellerScope()
    {
        $user = auth()->user();

        if ($user->hasRole('Reseller') && $user->reseller) {
            return CustomersInfo::where('reseller_id', $user->reseller->id);
        }

        return CustomersInfo::query();
    }

    public function updatingCustomerList()
    {
        $this->limit = 10;
    }

    public function updatedCustomerList()
    {
        if ($this->customer_list) {
            // Fetch customers dynamically based on the search term
            $this->customers = $this->resellerScope()
                ->search($this->customer_list)
                ->leftJoin('p_p_p_secrets', 'p_p_p_secrets.id', '=', 'customers_infos.ppp_user_id')
                ->with('customerAddress')
                ->select('customers_infos.id', 'customers_infos.customer_unique_id', 'customers_infos.customer_name', 'customers_infos.email', 'customers_infos.mobile', 'customers_infos.status', 'p_p_p_secrets.username as username')
                ->take($this->limit)
                ->get();
        } else {
            $this->customers = [];
        }

        // Reset highlighted index whenever the list updates
        $this->highlightedIndex = 0;
    }

    public function loadMore()
    {
        $this->limit += 10;
        $this->updatedCustomerList();
    }

    public function incrementHighlight()
    {
        if ($this->highlightedIndex < count($this->customers) - 1) {
            $this->highlightedIndex++;
        }
    }

    public function decrementHighlight()
    {
        if ($this->highlightedIndex > 0) {
            $this->highlightedIndex--;
        }
    }

    public function selectHighlightedCustomer()
    {
        if (isset($this->customers[$this->highlightedIndex])) {
            $selectedCustomer = $this->customers[$this->highlightedIndex];
            $this->selectCustomer(encrypt($selectedCustomer->customer_unique_id));
        }
    }

    public function calculatePayment()
    {
        if (! $this->info_data?->billing) {
            return;
        }

        $billing = $this->info_data->billing;
        $amount = max(0, (float) ($this->paid_amount ?? 0));
        $currentDue = (float) $billing->due_amount;
        $this->due_amount = round($currentDue - $amount, 2);
        $this->advance_paid = max(0, round($amount - max(0, $currentDue), 2));

        if ($billing->billing_type === 'prepaid') {
            $anchor = $this->info_data->connection_date ?: $billing->auto_disable_date;
            $calculatedExpiry = PrepaidBillingCycle::expiryAfterPayment(
                $anchor,
                $billing->auto_disable_date,
                $currentDue,
                $amount,
                (float) $billing->monthly_rent
            );
            $this->expire_date = $calculatedExpiry?->toDateString()
                ?: ($billing->auto_disable_date ? Carbon::parse($billing->auto_disable_date)->toDateString() : '');

            return;
        }

        if ($amount <= 0) {
            $this->expire_date = '';
            return;
        }

        $rent = max(1, (int) $billing->monthly_rent);
        if ($this->due_amount > 0) {
            $extraMonth = (int) floor($this->due_amount / $rent);
            $base = Carbon::parse($billing->auto_disable_date)->month(now()->month)->year(now()->year);
            $this->expire_date = ($extraMonth < (int) $billing->auto_disable_month
                ? $base->subMonths($extraMonth)
                : $base->subMonths((int) $billing->auto_disable_month)->addMonth())
                ->format('Y-m-d');
        } elseif ($this->advance_paid > 0) {
            $extraMonth = 1 + (int) floor($this->advance_paid / $rent);
            $this->expire_date = Carbon::parse($billing->auto_disable_date)
                ->month(now()->month)->year(now()->year)->addMonths($extraMonth)->format('Y-m-d');
        } else {
            $this->expire_date = Carbon::parse($billing->auto_disable_date)
                ->month(now()->month)->year(now()->year)->addMonth()->format('Y-m-d');
        }
    }

    public function selectCustomer($value)
    {
        $this->authorizePaymentCollection();
        // $this->paid_amount;
        $this->expire_date = '';
        $customer_id = decrypt($value);
        $this->customer_list = '';
        $this->customers = [];
        $this->info_data = $this->resellerScope()
            ->where('customer_unique_id', $customer_id)
            ->with([
                'customerAddress',
                'billing',
                'official',
                'pppUser',
                // 'collectionSummary' => function ($query) {
                //     $query->whereMonth('collection_date', Carbon::now()->month)
                //         ->whereYear('collection_date', Carbon::now()->year);
                // }
            ])
            ->first();
        $this->collectionSummary = CollectionSummary::where('customer_collection_unique_id', $customer_id)
            ->whereMonth('collection_date', Carbon::now()->month)
            ->whereYear('collection_date', Carbon::now()->year)
            ->get();
        $this->paid_amount = '';
        $this->payment_method = 'cash';
        $this->transaction_id = '';
        $this->payment_details = '';
        $this->collection_date = now()->format('Y-m-d\TH:i');
        $this->send_sms = true;
        $this->payment_attempt_key = (string) Str::uuid();
        $this->total_amount = $this->info_data->billing->due_amount;
        $this->due_amount = (float) $this->total_amount;
        if ($this->info_data->billing->billing_type === 'prepaid') {
            $this->expire_date = $this->info_data->billing->auto_disable_date
                ? Carbon::parse($this->info_data->billing->auto_disable_date)->toDateString()
                : '';
        }
    }

    public function savePayment(): bool
    {
        // Payment recording and service activation are separate operations.
        // A failed activation must never make the operator submit the receipt again.
        return $this->paymentSubmit();
    }

    public function paymentSubmit(): bool
    {
        $this->authorizePaymentCollection();
        if (! $this->info_data || ! $this->info_data->billing) {
            flash()->error('Select a valid customer with billing information first.');
            return false;
        }

        $customerUniqueId = (string) $this->info_data->customer_unique_id;
        $isPrepaid = $this->info_data->billing->billing_type === 'prepaid';
        $this->validate([
            'paid_amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_method' => ['required', Rule::in(['cash', 'bkash', 'nagad', 'rocket', 'bank', 'other'])],
            'transaction_id' => ['nullable', 'string', 'max:120', Rule::unique('collection_summaries', 'transaction_id')->where(fn ($q) => $q->whereNotNull('transaction_id'))],
            'payment_details' => ['nullable', 'string', 'max:2000'],
            'collection_date' => ['required', 'date', 'before_or_equal:now'],
            'expire_date' => [$isPrepaid ? 'nullable' : 'required', 'date'],
            'send_sms' => ['boolean'],
        ]);

        $amount = round((float) $this->paid_amount, 2);
        $collectionDate = Carbon::parse($this->collection_date);
        $attemptKey = filled($this->payment_attempt_key)
            ? (string) $this->payment_attempt_key
            : (string) Str::uuid();

        // A same-day identical cash receipt is usually an accidental retry.
        // Require a separate receipt reference or an explicit note to record it again.
        if (! filled($this->transaction_id) && ! filled($this->payment_details)) {
            $duplicateReceipt = CollectionSummary::query()
                ->where('customer_collection_unique_id', $customerUniqueId)
                ->where('payment_status', 'paid')
                ->where('payment_method', $this->payment_method)
                ->where('collection_amount', $amount)
                ->whereNull('transaction_id')
                ->whereDate('collection_date', $collectionDate->toDateString())
                ->exists();

            if ($duplicateReceipt) {
                $this->addError('payment_details', 'An identical payment is already recorded for this customer today. Check Collection History. For a separate real receipt, enter its receipt/reference ID or explain it in Notes.');
                return false;
            }
        }

        DB::beginTransaction();
        try {
            // The unique attempt key protects against a retried Livewire request
            // when the database committed but the browser did not receive the reply.
            if (CollectionSummary::where('payment_attempt_key', $attemptKey)->exists()) {
                DB::rollBack();
                flash()->warning('This payment request was already recorded. Do not submit it again.');
                return false;
            }

            $billing = BillingInfo::where('customer_bill_unique_id', $customerUniqueId)
                ->lockForUpdate()
                ->firstOrFail();
            $currentDue = (float) $billing->due_amount;
            $newDue = round($currentDue - $amount, 2);
            $newExpiry = null;

            if ($billing->billing_type === 'prepaid') {
                $cycleAnchor = $this->info_data->connection_date ?: $billing->auto_disable_date;
                $newExpiry = PrepaidBillingCycle::expiryAfterPayment(
                    $cycleAnchor,
                    $billing->auto_disable_date,
                    $currentDue,
                    $amount,
                    (float) $billing->monthly_rent
                );
                if ($newExpiry) {
                    $billing->auto_disable_date = $newExpiry->toDateString();
                }
            } else {
                $billing->auto_disable_date = $this->expire_date;
            }

            CollectionSummary::create([
                'customer_collection_unique_id' => $customerUniqueId,
                'collection_date' => $collectionDate,
                'collection_amount' => $amount,
                'collected_by' => auth()->user()->email,
                'payment_type' => $this->payment_method === 'cash' ? 'cash' : 'online',
                'payment_method' => $this->payment_method,
                'transaction_id' => filled($this->transaction_id) ? trim($this->transaction_id) : null,
                'payment_attempt_key' => $attemptKey,
                'details' => filled($this->payment_details) ? trim($this->payment_details) : null,
                'payment_status' => 'paid',
                'invoice_no' => CollectionSummary::nextInvoiceNumber(),
            ]);

            $billing->paid_amount = round((float) $billing->paid_amount + $amount, 2);
            $billing->paid_date = $collectionDate;
            $billing->due_amount = $newDue;
            if ($billing->billing_type === 'prepaid') {
                app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($billing);
            } else {
                $billing->save();
            }

            DB::commit();
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            report($th);
            \Log::error('Payment collection transaction failed.', [
                'customer_unique_id' => $customerUniqueId,
                'payment_attempt_key' => $attemptKey,
                'error' => $th->getMessage(),
            ]);
            sweetalert()->error('Unable to process the payment action. Check Collection History before retrying.', ['title' => 'Payment not confirmed']);
            return false;
        }

        // Activation is deliberately separate from receipt persistence. A router
        // failure leaves the paid receipt intact and can be retried without paying again.
        $freshCustomer = CustomersInfo::with('billing', 'pppUser')
            ->where('customer_unique_id', $customerUniqueId)
            ->first();
        if ($freshCustomer && in_array($freshCustomer->status, ['pending', 'disable'], true)) {
            $canEnable = true;
            if ($freshCustomer->billing?->billing_type === 'prepaid') {
                $canEnable = (float) $freshCustomer->billing->due_amount <= 0.009
                    && $freshCustomer->billing->auto_disable_date
                    && Carbon::parse($freshCustomer->billing->auto_disable_date)->startOfDay()->gt(today());
            }

            if ($canEnable) {
                try {
                    $response = (new CustomersController)->customerEnable(encrypt($customerUniqueId));
                    $result = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
                    if (($result['success'] ?? true) === false) {
                        flash()->warning('Payment recorded. Activation did not finish: '.($result['message'] ?? 'Use Retry Enable without collecting another payment.'));
                    } elseif (! empty($result['router_sync_pending'])) {
                        flash()->warning('Payment recorded. MikroTik activation is queued. Do not collect the same bill again; use Retry Enable if needed.');
                    }
                } catch (\Throwable $activationError) {
                    report($activationError);
                    flash()->warning('Payment recorded, but activation could not be confirmed. Use Retry Enable without collecting another payment.');
                }
            } elseif ($freshCustomer->billing?->billing_type === 'prepaid') {
                flash()->warning('Payment recorded, but the prepaid due is not fully covered. Customer was not enabled.');
            }
        }

        try {
            event(new \App\Events\PackagePurchased($freshCustomer ?: $this->info_data, $amount));
            flash()->success('Payment recorded successfully. Check Collection History before making another receipt.');
            $data = [
                'recipient' => $this->info_data->mobile,
                'customer_name' => $this->info_data->customer_name,
                'collection_amount' => $amount,
                'ip_or_user_name' => $this->info_data->pppUser->username ?? '',
                'due_amount' => $freshCustomer?->billing?->due_amount ?? $this->due_amount,
                'company_name' => siteUrlSettings('site_name'),
            ];

            $response = $this->send_sms ? app(SMSController::class)->paymentCollectionSMS($data) : null;
            if ($this->send_sms && $response && $response->isSuccessful()) {
                flash()->success($response->getMessage());
            } elseif ($this->send_sms) {
                flash()->warning('Payment was recorded, but the SMS receipt was not confirmed.');
            }
        } catch (\Throwable $th) {
            report($th);
            flash()->warning('Payment was recorded. A post-payment notification failed; do not submit the receipt again.');
        } finally {
            $this->reset();
            $this->payment_attempt_key = (string) Str::uuid();
            $this->dispatch('focusInput');
        }

        return true;
    }

    public function enableSelectedCustomerWithoutPayment(): bool
    {
        $this->authorizePaymentCollection();
        if (! $this->info_data || ! in_array($this->info_data->status, ['pending', 'disable'], true)) {
            flash()->warning('Select a pending or disabled customer first.');
            return false;
        }

        $customerUniqueId = (string) $this->info_data->customer_unique_id;
        $billing = BillingInfo::where('customer_bill_unique_id', $customerUniqueId)->first();
        if ($billing?->billing_type === 'prepaid') {
            if ((float) $billing->due_amount > 0.009
                || ! $billing->auto_disable_date
                || Carbon::parse($billing->auto_disable_date)->startOfDay()->lte(today())) {
                flash()->warning('Prepaid expiry/payment balance must be reconciled first. This action will not create another payment.');
                return false;
            }
        }

        try {
            $response = (new CustomersController)->customerEnable(encrypt($customerUniqueId));
            $result = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
            if (($result['success'] ?? true) === false) {
                flash()->error($result['message'] ?? 'Customer activation failed.');
                return false;
            }
            if (! empty($result['router_sync_pending'])) {
                flash()->warning('Customer enabled in billing. MikroTik activation is queued; no new payment was recorded.');
            } else {
                flash()->success('Customer enabled. No new payment was recorded.');
            }
            $this->info_data->refresh();
            $this->info_data->load('billing', 'pppUser');
            return true;
        } catch (\Throwable $e) {
            report($e);
            flash()->error('Enable retry failed. No new payment was recorded.');
            return false;
        }
    }

    public function render()
    {
        return view('livewire.payment-collection')->layout('layouts.app');
    }
}
