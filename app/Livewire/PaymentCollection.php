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
use Livewire\Attributes\On;
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

    public function mount()
    {
        $this->authorizePaymentCollection();
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
        if ($this->info_data) {
            $this->due_amount = (int) $this->info_data->billing->due_amount - (int) $this->paid_amount;
        }

        if ($this->paid_amount >= 0 && $this->info_data) {
            $this->advance_paid = (int) $this->paid_amount - (int) $this->total_amount;

            if ($this->due_amount > 0) {
                $extra_month = floor(((int) $this->due_amount) / (int) ($this->info_data->billing->monthly_rent == 0 || $this->info_data->billing->monthly_rent == null ? 1 : $this->info_data->billing->monthly_rent));

                if ($extra_month < $this->info_data->billing->auto_disable_month) {
                    $this->expire_date = Carbon::parse($this->info_data->billing->auto_disable_date)->month(now()->month)->year(now()->year)
                        ->subMonths($extra_month)
                        ->format('Y-m-d');
                } else {
                    $this->expire_date = Carbon::parse($this->info_data->billing->auto_disable_date)->month(now()->month)->year(now()->year)
                        ->subMonths($this->info_data->billing->auto_disable_month)
                        ->addMonths(1)
                        ->format('Y-m-d');
                }
            } elseif ($this->advance_paid > 0) {
                if ($this->info_data->billing->monthly_rent == 0 || $this->info_data->billing->monthly_rent == null) {
                    $extra_month = 1;
                } else {
                    $extra_month = 1 + floor(((int) $this->advance_paid) / (int) $this->info_data->billing->monthly_rent);
                }

                $this->expire_date = Carbon::parse($this->info_data->billing->auto_disable_date)->month(now()->month)->year(now()->year)
                    ->addMonths($extra_month)
                    ->format('Y-m-d');
            } else {
                $this->expire_date = Carbon::parse($this->info_data->billing->auto_disable_date)->month(now()->month)->year(now()->year)
                    ->addMonths(1)
                    ->format('Y-m-d');
            }
        } else {
            $this->expire_date = '';
        }

        if ($this->info_data?->billing?->billing_type === 'prepaid' && filled($this->expire_date)) {
            $cycleAnchor = $this->info_data->connection_date
                ?: ($this->info_data->billing->auto_disable_date ?: today()->toDateString());
            $this->expire_date = PrepaidBillingCycle::alignToConnectionDay($cycleAnchor, $this->expire_date)->toDateString();
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
        $this->total_amount = $this->info_data->billing->due_amount;
        $this->due_amount = (int) $this->total_amount - (int) $this->paid_amount;
    }

    public function savePayment()
    {
        $this->authorizePaymentCollection();
        if ($this->info_data->status != 'active') {
            sweetalert()->showDenyButton()->info('Are you sure you want to Enable this customer?');
        }
        if ($this->info_data->status == 'active') {
            $this->paymentSubmit();
        }
    }

    #[On('sweetalert:confirmed')]
    public function onConfirmed(array $payload): void
    {
        $this->authorizePaymentCollection();
        $customerUniqueId = is_array($this->info_data)
            ? ($this->info_data['customer_unique_id'] ?? null)
            : ($this->info_data->customer_unique_id ?? null);

        $customerStatus = $customerUniqueId
            ? CustomersInfo::where('customer_unique_id', $customerUniqueId)->value('status')
            : null;

        // Record the cash receipt before attempting activation. Do not pre-set the
        // status: CustomersController::customerEnable must see pending/disable.
        if (! $this->paymentSubmit()) {
            return;
        }

        if (! $customerUniqueId || ! in_array($customerStatus, ['pending', 'disable'], true)) {
            return;
        }

        try {
            $response = (new CustomersController)->customerEnable(encrypt($customerUniqueId));
            $result = $response instanceof \Illuminate\Http\JsonResponse
                ? $response->getData(true)
                : [];

            if (($result['success'] ?? true) === false) {
                flash()->addError('Cash payment recorded, but customer activation did not complete: '.($result['message'] ?? 'Please use Enable from the Customer List.'));
            } elseif (! empty($result['router_sync_pending'])) {
                flash()->addError('Cash payment recorded. MikroTik activation is queued and will retry when the router is available.');
            }
        } catch (\Throwable $e) {
            report($e);
            flash()->addError('Cash payment recorded, but customer activation could not be confirmed. Retry Enable from the Customer List.');
        }
    }

    #[On('sweetalert:denied')]
    public function onDeny(array $payload): void
    {
        $this->authorizePaymentCollection();
        $this->paymentSubmit();
    }

    public function paymentSubmit(): bool
    {
        $this->authorizePaymentCollection();
        DB::beginTransaction();
        try {
            CollectionSummary::create([
                'customer_collection_unique_id' => $this->info_data->customer_unique_id,
                'collection_date' => Carbon::now(),
                'collection_amount' => $this->paid_amount,
                'collected_by' => auth()->user()->email,
                'payment_status' => 'paid',
            ]);

            $billing = BillingInfo::where('customer_bill_unique_id', $this->info_data->customer_unique_id)
                ->lockForUpdate()
                ->firstOrFail();
            $billing->paid_amount = $this->paid_amount + $billing->paid_amount;
            $billing->paid_date = Carbon::now();
            $billing->auto_disable_date = $this->expire_date;
            $billing->due_amount = $this->due_amount;
            app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($billing);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            report($th);
            sweetalert()->error('Unable to process the payment action. Please try again.', ['title' => 'Error']);
            return false;
        }

        try {
            // Fire reseller commission event
            event(new \App\Events\PackagePurchased($this->info_data, (float) $this->paid_amount));

            flash()->success('Payment added successfully.');
            $data = [
                'recipient' => $this->info_data->mobile,
                'customer_name' => $this->info_data->customer_name,
                'collection_amount' => $this->paid_amount,
                'ip_or_user_name' => $this->info_data->pppUser->username ?? '',
                'due_amount' => $this->due_amount,
                'company_name' => siteUrlSettings('site_name'),
            ];

            // Call the SMSController's method
            $response = app(SMSController::class)->paymentCollectionSMS($data);
            if ($response && $response->isSuccessful()) {
                flash()->success($response->getMessage());
            } else {
                flash()->error('Failed to send SMS notification. Please try again.');
            }
        } catch (\Throwable $th) {
            report($th);
            // The ledger transaction has already committed at this point.
            // Never show a generic payment-failure prompt that could cause a duplicate collection.
            flash()->warning('Payment was recorded, but a post-payment action failed. Check the collection history before retrying.');
        } finally {
            $this->reset();
            $this->dispatch('focusInput');
        }

        return true;
    }

    public function render()
    {
        return view('livewire.payment-collection')->layout('layouts.app');
    }
}
