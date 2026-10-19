<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\PackageList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MobileApiController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $customers = $this->customerQueryFor($user);
        $cidQuery = (clone $customers)->select('customer_unique_id');

        $canViewCustomers = $this->can($user, ['view-customer', 'all-customer']) || $user->hasRole('Reseller');
        $canViewCollections = $this->can($user, ['payment-history', 'payment-collection', 'payment-collection-report']) || $user->hasRole('Reseller');

        $stats = [
            'active_customers' => $canViewCustomers ? (clone $customers)->where('status', 'active')->count() : null,
            'pending_customers' => $canViewCustomers ? (clone $customers)->where('status', 'pending')->count() : null,
            'total_customers' => $canViewCustomers ? (clone $customers)->count() : null,
            'outstanding_due' => null,
            'collections_this_month' => null,
        ];

        if ($canViewCustomers) {
            $stats['outstanding_due'] = (float) BillingInfo::query()
                ->whereIn('customer_bill_unique_id', $cidQuery)
                ->sum('due_amount');
        }

        if ($canViewCollections) {
            $stats['collections_this_month'] = (float) CollectionSummary::query()
                ->whereIn('customer_collection_unique_id', $cidQuery)
                ->whereBetween('collection_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->whereIn('payment_status', ['paid', 'success', 'completed'])
                ->sum('collection_amount');
        }

        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'user' => ['id' => $user->id, 'name' => $user->name, 'roles' => $user->getRoleNames()->values()],
                'stats' => $stats,
            ],
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('Reseller') || $this->can($user, ['view-customer', 'all-customer']), 403, 'You do not have permission to view customers.');

        $query = $this->customerQueryFor($user)->with(['package', 'billing']);
        if ($request->filled('q')) {
            $query->search(trim((string) $request->query('q')));
        }
        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            abort_unless(in_array($status, ['active', 'pending', 'inactive', 'disable', 'free'], true), 422, 'Unsupported customer status filter.');
            $query->where('status', $status);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->orderBy('customer_name')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (CustomersInfo $customer) => $this->customerPayload($customer))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    public function customer(Request $request, int $customer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('Reseller') || $this->can($user, ['view-customer', 'all-customer']), 403, 'You do not have permission to view customers.');

        $record = $this->customerQueryFor($user)
            ->with(['package', 'billing', 'pppUser', 'customerAddress'])
            ->whereKey($customer)
            ->firstOrFail();

        return response()->json(['data' => $this->customerPayload($record, true)]);
    }

    public function billing(Request $request, int $customer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('Reseller') || $this->can($user, ['customer-billing-info', 'update-bill', 'payment-history']), 403, 'You do not have permission to view billing information.');

        $record = $this->customerQueryFor($user)->whereKey($customer)->firstOrFail();
        $billing = BillingInfo::query()->where('customer_bill_unique_id', $record->customer_unique_id)->first();
        abort_if(! $billing, 404, 'Billing information was not found.');

        return response()->json([
            'data' => [
                'customer_id' => $record->id,
                'customer_unique_id' => $record->customer_unique_id,
                'billing_type' => $billing->billing_type,
                'monthly_rent' => (float) $billing->monthly_rent,
                'additional_charge' => (float) $billing->additional_charge,
                'discount' => (float) $billing->discount,
                'vat' => (float) $billing->vat,
                'previous_due' => (float) $billing->previous_due,
                'total_amount' => (float) $billing->total_amount,
                'paid_amount' => (float) $billing->paid_amount,
                'due_amount' => (float) $billing->due_amount,
                'paid_date' => $this->dateValue($billing->paid_date),
                'next_disable_date' => $this->dateValue($billing->auto_disable_date),
            ],
        ]);
    }

    public function paymentHistory(Request $request, int $customer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('Reseller') || $this->can($user, ['payment-history', 'payment-collection-report', 'payment-collection']), 403, 'You do not have permission to view payment history.');

        $record = $this->customerQueryFor($user)->whereKey($customer)->firstOrFail();
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $payments = CollectionSummary::query()
            ->where('customer_collection_unique_id', $record->customer_unique_id)
            ->latest('collection_date')
            ->paginate($perPage);

        return response()->json([
            'data' => $payments->getCollection()->map(fn (CollectionSummary $payment) => [
                'id' => $payment->id,
                'invoice_no' => $payment->invoice_no,
                'date' => $this->dateValue($payment->collection_date),
                'amount' => (float) $payment->collection_amount,
                'status' => $payment->payment_status,
                'method' => $payment->payment_method,
                'type' => $payment->payment_type,
                'bill_month' => $payment->bill_month,
            ])->values(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('Reseller') || $this->can($user, ['view-customer', 'all-customer', 'package-setup']), 403, 'You do not have permission to view internet packages.');

        $packages = PackageList::query()
            ->where('show_on_site', true)
            ->when($user->hasRole('Reseller'), function (Builder $query) use ($user) {
                $resellerId = $user->reseller?->id;
                abort_unless($resellerId, 403, 'This reseller account is not linked to a reseller profile.');
                $query->where(function (Builder $visiblePackages) use ($resellerId) {
                    $visiblePackages->whereNull('reseller_id')->orWhere('reseller_id', $resellerId);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('package')
            ->get(['id', 'package', 'price', 'description', 'plan_label', 'speed', 'features', 'is_featured']);

        return response()->json([
            'data' => $packages->map(fn (PackageList $package) => [
                'id' => $package->id,
                'name' => $package->package,
                'price' => (float) $package->price,
                'description' => $package->description,
                'plan_label' => $package->plan_label,
                'speed' => $package->speed,
                'features' => $package->features ?? [],
                'is_featured' => (bool) $package->is_featured,
            ])->values(),
        ]);
    }

    private function customerQueryFor(User $user): Builder
    {
        $query = CustomersInfo::query();

        if ($user->hasRole('Reseller')) {
            $resellerId = $user->reseller?->id;
            abort_unless($resellerId, 403, 'This reseller account is not linked to a reseller profile.');
            $query->where('reseller_id', $resellerId);
        }

        return $query;
    }

    private function can(User $user, array $permissions): bool
    {
        return $user->hasRole('Super Admin') || $user->hasAnyPermission($permissions);
    }

    private function dateValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function customerPayload(CustomersInfo $customer, bool $detailed = false): array
    {
        $data = [
            'id' => $customer->id,
            'customer_unique_id' => $customer->customer_unique_id,
            'name' => $customer->customer_name,
            'contact_person' => $customer->contact_person,
            'mobile' => $customer->mobile,
            'alternative_mobile' => $customer->alternative_mobile,
            'email' => $customer->email,
            'status' => $customer->status,
            'connection_date' => $this->dateValue($customer->connection_date),
            'package' => $customer->package ? [
                'id' => $customer->package->id,
                'name' => $customer->package->package,
                'price' => (float) $customer->package->price,
                'speed' => $customer->package->speed,
            ] : null,
            'billing' => $customer->billing ? [
                'monthly_rent' => (float) $customer->billing->monthly_rent,
                'paid_amount' => (float) $customer->billing->paid_amount,
                'due_amount' => (float) $customer->billing->due_amount,
                'auto_disable_date' => $this->dateValue($customer->billing->auto_disable_date),
            ] : null,
        ];

        if ($detailed) {
            $data['address'] = $customer->address;
            $data['pppoe_username'] = $customer->pppUser?->username;
            // PPPoE passwords are never returned by the mobile API.
            $data['addresses'] = $customer->customerAddress->map(fn ($address) => [
                'floor_flat' => $address->floor_flat ?? null,
                'road' => $address->road ?? null,
                'house' => $address->house ?? null,
                'district' => $address->district ?? null,
                'thana_upazila' => $address->thana_upazila ?? null,
                'pop' => $address->pop ?? null,
            ])->values();
        }

        return $data;
    }
}
