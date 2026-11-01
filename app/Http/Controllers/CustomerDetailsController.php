<?php

namespace App\Http\Controllers;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\CollectionSummary;
use App\Models\PaymentSummary;
use Carbon\Carbon;
use App\Models\OltOnuCustomerMapping;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class CustomerDetailsController extends Controller
{
    public function show(string $id)
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $uniqueId = $this->resolveCustomerId($id);
        $customer = CustomersInfo::withTrashed()
            ->with(['pppUser', 'package', 'billing', 'official', 'customerAddress'])
            ->where('customer_unique_id', $uniqueId)
            ->first();

        // After CID reuse, historical customers retain a private archive key.
        // Resolve their old human-readable CID only when no current CID matches.
        if (! $customer) {
            $customer = CustomersInfo::withTrashed()
                ->with(['pppUser', 'package', 'billing', 'official', 'customerAddress'])
                ->where('deleted_original_customer_unique_id', $uniqueId)
                ->firstOrFail();
        }

        $customerStorageKey = (string) $customer->customer_unique_id;

        $mapping = Schema::hasTable('olt_onu_customer_mappings')
            ? OltOnuCustomerMapping::with('olt')
                ->where('customer_id', $customer->id)
                ->latest('id')->first()
            : null;

        $activities = class_exists(Activity::class)
            ? Activity::with('causer')->where(function ($q) use ($customerStorageKey, $customer) {
                $q->where('properties->customer_unique_id', $customerStorageKey)
                  ->orWhere('properties->customer_id', $customer->id);
            })->latest()->limit(8)->get()
            : collect();

        // PPPoE remote IP may be assigned dynamically from a MikroTik pool.
        // Prefer the stored secret value; when it is empty, read the live
        // /ppp active session for this username and use its current address.
        $remoteIp = $customer->pppUser?->ppp_remote_ip ?: null;
        if (! $remoteIp
            && strtolower((string) $customer->pppUser?->service) === 'pppoe'
            && $customer->pppUser?->router_name
            && $customer->pppUser?->username) {
            try {
                $sessions = app(MikrotikController::class)->getActivePppSessions($customer->pppUser->router_name);
                foreach ($sessions as $session) {
                    if (($session['name'] ?? '') === $customer->pppUser->username) {
                        $remoteIp = $session['address'] ?? $session['remote-address'] ?? null;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return view('customers.show', compact('customer', 'mapping', 'activities', 'remoteIp'));
    }

    public function paidLedger(string $id)
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $uniqueId = $this->resolveCustomerId($id);
        $customer = CustomersInfo::withTrashed()
            ->with(['billing', 'package'])
            ->where('customer_unique_id', $uniqueId)
            ->first();

        if (! $customer) {
            $customer = CustomersInfo::withTrashed()
                ->with(['billing', 'package'])
                ->where('deleted_original_customer_unique_id', $uniqueId)
                ->firstOrFail();
        }

        // Reconstruct each billing month from its immutable monthly bill summary
        // and the paid collection ledger. BillingInfo is a current-cycle snapshot
        // and is intentionally not used for historical paid totals.
        $months = [];
        $billSummaries = PaymentSummary::query()
            ->where('customer_payment_unique_id', $customer->customer_unique_id)
            ->orderBy('summary_date')
            ->get();

        foreach ($billSummaries as $summary) {
            $monthKey = Carbon::parse($summary->summary_date)->format('Y-m');
            $months[$monthKey] ??= [
                'month' => Carbon::parse($summary->summary_date)->startOfMonth(),
                'bill' => 0.0,
                'received' => 0.0,
                'returned' => 0.0,
                'net_paid' => 0.0,
                'transactions' => 0,
                'has_bill' => true,
            ];
            $months[$monthKey]['bill'] += (float) $summary->total;
            $months[$monthKey]['has_bill'] = true;
        }

        $collectionMonths = CollectionSummary::query()
            ->where('customer_collection_unique_id', $customer->customer_unique_id)
            ->where('payment_status', 'paid')
            ->selectRaw("DATE_FORMAT(collection_date, '%Y-%m') as month_key, COUNT(*) as transaction_count, SUM(CASE WHEN collection_amount > 0 THEN collection_amount ELSE 0 END) as received_amount, SUM(CASE WHEN collection_amount < 0 THEN ABS(collection_amount) ELSE 0 END) as returned_amount, SUM(collection_amount) as net_paid_amount")
            ->groupByRaw("DATE_FORMAT(collection_date, '%Y-%m')")
            ->get();

        foreach ($collectionMonths as $collectionMonth) {
            $monthKey = (string) $collectionMonth->month_key;
            $months[$monthKey] ??= [
                'month' => Carbon::createFromFormat('!Y-m', $monthKey),
                'bill' => null,
                'received' => 0.0,
                'returned' => 0.0,
                'net_paid' => 0.0,
                'transactions' => 0,
                'has_bill' => false,
            ];
            $months[$monthKey]['received'] = (float) $collectionMonth->received_amount;
            $months[$monthKey]['returned'] = (float) $collectionMonth->returned_amount;
            $months[$monthKey]['net_paid'] = (float) $collectionMonth->net_paid_amount;
            $months[$monthKey]['transactions'] = (int) $collectionMonth->transaction_count;
        }

        krsort($months);
        $paidLedger = collect($months)->map(function (array $row) {
            $row['balance'] = $row['has_bill']
                ? (float) $row['bill'] - (float) $row['net_paid']
                : null;
            return $row;
        })->values();

        $totals = [
            'bill' => (float) $paidLedger->where('has_bill', true)->sum('bill'),
            'received' => (float) $paidLedger->sum('received'),
            'returned' => (float) $paidLedger->sum('returned'),
            'net_paid' => (float) $paidLedger->sum('net_paid'),
            'transactions' => (int) $paidLedger->sum('transactions'),
        ];

        return view('customers.paid-ledger', compact('customer', 'paidLedger', 'totals'));
    }

    private function resolveCustomerId(string $id): string
    {
        try {
            $decrypted = decrypt($id);
            if (is_string($decrypted) && $decrypted !== '') {
                return $decrypted;
            }
        } catch (\Throwable) {
            // The public customer URL may use the manual Customer Unique ID.
        }

        return $id;
    }
}
