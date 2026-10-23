<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\HotspotSale;
use App\Models\PPPSecrets;
use App\Models\Reseller;
use App\Models\ResellerCommission;
use App\Models\SupportTicket;
use App\Models\KycRequest;
use App\Models\NotificationLogs;
use App\Services\PrepaidBillingCycle;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->check() && auth()->user()->hasRole('Reseller')) {
            $route = auth()->user()->reseller?->isMacPanel()
                ? 'mac-reseller.dashboard'
                : 'reseller.dashboard';
            return redirect()->route($route);
        }

        $results = [];

        $currentYear = Carbon::now()->year;
        $previousYear = Carbon::now()->subYear()->year;

        // Group status counts to execute a single query instead of 6 count queries
        $statusCounts = CustomersInfo::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentCount = CustomersInfo::whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        $customersData = [
            'total' => array_sum($statusCounts),
            'active' => $statusCounts['active'] ?? 0,
            'pending' => $statusCounts['pending'] ?? 0,
            'free' => $statusCounts['free'] ?? 0,
            'temporary_disable' => $statusCounts['disable'] ?? 0,
            'inactive' => $statusCounts['inactive'] ?? 0,
            'recent' => $recentCount,
        ];

        // Optimized query: sum columns directly on the database using Eloquent model
        $billingStats = BillingInfo::join('customers_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->whereNull('customers_infos.deleted_at')
            ->selectRaw("
                SUM(billing_infos.monthly_rent) as monthly_rent,
                SUM(billing_infos.advance) as advance,
                SUM(billing_infos.paid_amount) as paid_amount,
                SUM(CASE WHEN customers_infos.status != 'inactive' THEN billing_infos.previous_due ELSE 0 END) as previous_due_active,
                SUM(CASE WHEN customers_infos.status != 'inactive' THEN billing_infos.due_amount ELSE 0 END) as due_amount_active
            ")
            ->first();

        // Per-status billing breakdown for chart
        $statusBillingRaw = BillingInfo::join('customers_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->whereNull('customers_infos.deleted_at')
            ->whereIn('customers_infos.status', ['active', 'free', 'inactive', 'pending'])
            ->selectRaw("customers_infos.status,
                SUM(billing_infos.monthly_rent) as monthly_rent,
                SUM(billing_infos.advance) as advance,
                SUM(billing_infos.due_amount) as due_amount")
            ->groupBy('customers_infos.status')
            ->get()
            ->keyBy('status');

        $statuses = ['active', 'free', 'inactive', 'pending'];
        $billingByStatus = [];
        foreach ($statuses as $s) {
            $row = $statusBillingRaw->get($s);
            $billingByStatus[$s] = [
                'monthly_rent' => (float) ($row->monthly_rent ?? 0),
                'advance' => (float) ($row->advance ?? 0),
                'due_amount' => (float) ($row->due_amount ?? 0),
            ];
        }

        $billInformationData = [
            'monthly_rent' => (float) ($billingStats->monthly_rent ?? 0),
            'previous_due' => -1 * (float) ($billingStats->previous_due_active ?? 0),
            'advance' => (float) ($billingStats->advance ?? 0),
            'paid_amount' => (float) ($billingStats->paid_amount ?? 0),
            'today_paid_amount' => CollectionSummary::whereDate('collection_date', Carbon::today())->sum('collection_amount'),
            'hotspot_total' => HotspotSale::sum('amount'),
            'hotspot_today' => HotspotSale::whereDate('sale_date', Carbon::today())->sum('amount'),
            'due_amount' => -1 * (float) ($billingStats->due_amount_active ?? 0),
            'by_status' => $billingByStatus,
        ];

        // Reseller data for admin
        $resellerStatusCounts = Reseller::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $resellerData = [
            'total_resellers' => array_sum($resellerStatusCounts),
            'active_resellers' => $resellerStatusCounts['active'] ?? 0,
            'suspended_resellers' => $resellerStatusCounts['suspended'] ?? 0,
            'total_balance' => Reseller::sum('balance'),
            'total_customers' => CustomersInfo::whereNotNull('reseller_id')->count(),
            'active_customers' => CustomersInfo::whereNotNull('reseller_id')->where('status', 'active')->count(),
            'pending_customers' => CustomersInfo::whereNotNull('reseller_id')->where('status', 'pending')->count(),
            'total_commission' => ResellerCommission::sum('amount'),
        ];

        $isSqlite = config('database.default') === 'sqlite';
        $monthExpr = $isSqlite ? "CAST(strftime('%m', collection_date) AS INTEGER)" : 'MONTH(collection_date)';
        $hotspotMonthExpr = $isSqlite ? "CAST(strftime('%m', sale_date) AS INTEGER)" : 'MONTH(sale_date)';

        // Pre-group yearly collections/hotspots by month to avoid looping 48 SQL queries
        $collectionsPreviousYear = CollectionSummary::whereYear('collection_date', $previousYear)
            ->selectRaw("{$monthExpr} as month, SUM(collection_amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        $collectionsCurrentYear = CollectionSummary::whereYear('collection_date', $currentYear)
            ->selectRaw("{$monthExpr} as month, SUM(collection_amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        $hotspotPreviousYear = HotspotSale::whereYear('sale_date', $previousYear)
            ->selectRaw("{$hotspotMonthExpr} as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        $hotspotCurrentYear = HotspotSale::whereYear('sale_date', $currentYear)
            ->selectRaw("{$hotspotMonthExpr} as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Loop through each month using pre-fetched array maps
        for ($month = 1; $month <= 12; $month++) {
            $cashflowPreviousYear = ($collectionsPreviousYear[$month] ?? 0) + ($hotspotPreviousYear[$month] ?? 0);
            $incomeCurrentYear = ($collectionsCurrentYear[$month] ?? 0) + ($hotspotCurrentYear[$month] ?? 0);
            $revenueDifference = $incomeCurrentYear - $cashflowPreviousYear;

            $results[$month] = [
                'cashflow_previous_year' => (float) $cashflowPreviousYear,
                'income_current_year' => (float) $incomeCurrentYear,
                'revenue_difference' => (float) $revenueDifference,
            ];
        }

        try {
            $systemOverview = app(MikrotikController::class)->systemOverview();
        } catch (\Exception $e) {
            $systemOverview = [];
        }

        // Reference-aligned KPI metrics. These are derived from live application data, not demo snapshot values.
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $weekStart = Carbon::now()->subDays(6)->startOfDay();

        $activeCustomers = CustomersInfo::where('status', 'active');
        $activeCompany = (clone $activeCustomers)->whereNull('reseller_id')->count();
        $activeReseller = (clone $activeCustomers)->whereNotNull('reseller_id')->count();
        $activeCustomerTotal = $activeCompany + $activeReseller;
        // Match the existing Online Customers page: an active billing customer linked to an enabled PPP secret.
        // This is account readiness, not a live MikroTik active-session measurement.
        $onlineCompany = (clone $activeCustomers)->whereNull('reseller_id')->whereHas('pppUser', fn ($query) => $query->where('status', 'active'))->count();
        $onlineReseller = (clone $activeCustomers)->whereNotNull('reseller_id')->whereHas('pppUser', fn ($query) => $query->where('status', 'active'))->count();
        $onlineNow = $onlineCompany + $onlineReseller;
        // Live MikroTik telemetry is read-only and independent of RouterList 'connected' UI state.
        $livePppTelemetry = [
            'status' => 'unavailable', 'total' => null, 'successful_routers' => 0,
            'router_count' => 0, 'routers' => [], 'checked_at' => null,
        ];
        try {
            $livePppTelemetry = app(MikrotikController::class)->getLivePppTelemetry();
        } catch (\Throwable $e) {
            \Log::debug('Dashboard live PPP session read failed: '.$e->getMessage());
        }
        $livePppSessionsByRouter = $livePppTelemetry['routers'] ?? [];
        $livePppSessions = $livePppTelemetry['total'] ?? null;

        // Expired means active accounts whose billing disable/expiry date has passed.
        $expired = BillingInfo::join('customers_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->whereNull('customers_infos.deleted_at')
            ->where('customers_infos.status', 'active')
            ->whereNotNull('billing_infos.auto_disable_date')
            ->whereDate('billing_infos.auto_disable_date', '<', $today)
            ->count();

        $lockedDisabled = CustomersInfo::whereIn('status', ['disable', 'temporary_disable'])->count();

        // Use the same timezone and eligibility rules as the daily userDisable scheduler.
        $billingTimezone = config('app.timezone') ?: 'Asia/Dhaka';
        $billingToday = Carbon::now($billingTimezone)->startOfDay();
        $autoDisableResultsToday = NotificationLogs::query()
            ->whereDate('created_at', $billingToday->toDateString())
            ->where(function ($query) {
                $query->whereIn('title', ['Disconnection Success', 'Disconnection Failed', 'Disconnection Error', 'Disconnection Warning'])
                    ->orWhere('title', 'like', 'User(s) profile%HARD DISABLED%');
            })
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['title', 'message', 'status', 'type', 'created_at']);
        $autoDisableResultsTodayCount = NotificationLogs::query()
            ->whereDate('created_at', $billingToday->toDateString())
            ->where(function ($query) {
                $query->whereIn('title', ['Disconnection Success', 'Disconnection Failed', 'Disconnection Error', 'Disconnection Warning'])
                    ->orWhere('title', 'like', 'User(s) profile%HARD DISABLED%');
            })
            ->count();

        $expiringTodayBase = CustomersInfo::query()
            ->where('status', 'active')
            ->whereHas('billing', fn ($query) => $query
                ->whereNotNull('auto_disable_date')
                ->whereDate('auto_disable_date', $billingToday->toDateString()));

        $customersExpiringTodayCount = (clone $expiringTodayBase)->count();
        $customersExpiringToday = (clone $expiringTodayBase)
            ->with(['billing', 'pppUser'])
            ->orderBy('customer_name')
            ->limit(20)
            ->get();

        // Build a read-only preview of customers that satisfy today's scheduler disable rules.
        // This list is eligibility, not proof the router disconnect has already succeeded.
        $disableCheckNo = (int) (siteUrlSettings('disable_check_no') ?? 1);
        $autoDisableCandidates = CustomersInfo::active()
            ->where(function ($customerQuery) use ($disableCheckNo) {
                $customerQuery->where(function ($legacyQuery) use ($disableCheckNo) {
                    $legacyQuery->underDisableLimit($disableCheckNo)
                        ->whereHas('billing', fn ($query) => $query
                            ->autoDisable()
                            ->unpaid()
                            ->where('billing_type', '!=', 'prepaid'));
                })->orWhereHas('billing', fn ($query) => $query
                    ->autoDisable()
                    ->where('billing_type', 'prepaid')
                    ->where('monthly_rent', '>', 0));
            })
            ->hasPPPUser()
            ->with(['billing', 'pppUser'])
            ->get();

        $autoDisableToday = collect();
        $nowForDisable = Carbon::now($billingTimezone);

        foreach ($autoDisableCandidates as $customer) {
            $billing = $customer->billing;
            $pppUser = $customer->pppUser;

            if (! $billing || ! $pppUser || ! $pppUser->username) {
                continue;
            }

            $shouldDisable = false;
            $disableReason = '';

            if ($billing->billing_type === 'prepaid') {
                $cycleAnchor = $customer->connection_date ?: $billing->auto_disable_date;

                if (! $cycleAnchor) {
                    continue;
                }

                $autoDisableDate = $billing->auto_disable_date
                    ? PrepaidBillingCycle::alignToConnectionDay($cycleAnchor, $billing->auto_disable_date)
                    : PrepaidBillingCycle::nextExpiryDate($cycleAnchor, $billingToday);

                $shouldDisable = (float) $billing->monthly_rent > 0
                    && $billingToday->greaterThanOrEqualTo($autoDisableDate->copy()->startOfDay());
                $disableReason = 'Prepaid connection-day expiry';
            } else {
                // Mirror the postpaid/grace-period decision in ScheduledTasksController::userDisable().
                $baseDate = Carbon::parse($billing->auto_disable_date, $billingTimezone);

                if ($baseDate->isPast() && ($baseDate->month !== $nowForDisable->month || $baseDate->year !== $nowForDisable->year)) {
                    $baseDate->month($nowForDisable->month)->year($nowForDisable->year);
                }

                $autoDisableDate = $disableCheckNo <= 1
                    ? $baseDate->copy()->startOfDay()
                    : $baseDate->copy()->addDays((int) siteUrlSettings('disable_check_days') * $customer->disable_count)->startOfDay();

                $previousDueDisableDate = $disableCheckNo <= 1
                    ? Carbon::parse($billing->auto_disable_date, $billingTimezone)->month($nowForDisable->month)->year($nowForDisable->year)->startOfDay()
                    : Carbon::parse($billing->auto_disable_date, $billingTimezone)->month($nowForDisable->month)->year($nowForDisable->year)->addDays((int) siteUrlSettings('disable_check_days') * $customer->disable_count)->startOfDay();

                $autoDisableMonth = (int) ($billing->auto_disable_month ?? 0);
                $totalBill = ((float) $billing->monthly_rent + (float) $billing->additional_charge + (float) $billing->vat) * $autoDisableMonth;

                if ((float) $billing->previous_due > 0 && (float) $billing->due_amount > $totalBill && $billingToday->isSameDay($previousDueDisableDate)) {
                    $shouldDisable = true;
                    $disableReason = 'Auto Disable for Previous Due';
                } elseif ((float) $billing->due_amount > $totalBill && $billingToday->greaterThanOrEqualTo($autoDisableDate)) {
                    $shouldDisable = true;
                    $disableReason = 'Auto Disable for Due';
                } elseif ((float) $billing->due_amount > 0 && (float) $billing->due_amount <= $totalBill && $billingToday->greaterThanOrEqualTo($autoDisableDate->copy()->addMonths($autoDisableMonth))) {
                    $shouldDisable = true;
                    $disableReason = 'Auto Disable Date reached';
                }
            }

            if ($shouldDisable) {
                $customer->setAttribute('dashboard_disable_reason', $disableReason);
                $autoDisableToday->push($customer);
            }
        }

        $autoDisableToday = $autoDisableToday->sortBy('customer_name')->values();
        $autoDisableTodayCount = $autoDisableToday->count();
        $autoDisableToday = $autoDisableToday->take(20)->values();

        $runningDue = (float) (clone $activeCustomers)->join('billing_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->sum('billing_infos.due_amount');
        $runningDue = abs($runningDue);

        $monthCollectionPppoe = CollectionSummary::whereBetween('collection_date', [$monthStart, $monthEnd])->sum('collection_amount');
        $monthCollectionHotspot = HotspotSale::whereBetween('sale_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount');
        $monthCollection = (float) $monthCollectionPppoe + (float) $monthCollectionHotspot;

        $todayPppoe = CollectionSummary::whereDate('collection_date', $today)->sum('collection_amount');
        $todayHotspot = HotspotSale::whereDate('sale_date', $today)->sum('amount');
        $todayCollection = (float) $todayPppoe + (float) $todayHotspot;

        $weekPppoe = CollectionSummary::whereBetween('collection_date', [$weekStart, Carbon::now()->endOfDay()])->sum('collection_amount');
        $weekHotspot = HotspotSale::whereBetween('sale_date', [$weekStart->toDateString(), Carbon::now()->toDateString()])->sum('amount');
        $weekCollection = (float) $weekPppoe + (float) $weekHotspot;

        $mfsKeywords = ['bkash', 'nagad', 'rocket', 'upay', 'sslcommerz', 'mobile', 'mfs', 'sms', 'banking'];
        $activeTicketStatuses = ['new', 'open', 'pending', 'in_progress'];
        $openTickets = SupportTicket::whereIn('status', $activeTicketStatuses)->count();
        $overdueTickets = SupportTicket::whereIn('status', $activeTicketStatuses)
            ->where('created_at', '<=', Carbon::now()->subDay())->count();
        $ticketsToday = SupportTicket::whereDate('created_at', $today)->count();
        $pendingKyc = KycRequest::where('status', 'pending')->count();
        $kycApprovedMonth = KycRequest::where('status', 'reviewed')
            ->whereBetween('reviewed_at', [$monthStart, $monthEnd])->count();

        $mfsMethods = ['bkash', 'nagad', 'rocket', 'upay', 'sslcommerz'];
        $mfsPending = CollectionSummary::whereIn('payment_status', ['pending', 'processing', 'initiated'])
            ->where(function ($query) use ($mfsMethods) {
                $query->where('payment_type', 'online')->orWhereIn('payment_method', $mfsMethods);
            })->count();

        $mfsCollection = (float) CollectionSummary::whereBetween('collection_date', [$monthStart, $monthEnd])
            ->where(function ($q) use ($mfsKeywords) {
                foreach ($mfsKeywords as $keyword) {
                    $pattern = '%' . $keyword . '%';
                    $q->orWhereRaw("LOWER(COALESCE(payment_method,'')) LIKE ?", [$pattern])
                        ->orWhereRaw("LOWER(COALESCE(payment_type,'')) LIKE ?", [$pattern]);
                }
            })->sum('collection_amount');

        $resellerDue = max(0, (float) Reseller::sum('balance'));
        $recentTransactions = CollectionSummary::with('customer')->latest('collection_date')->latest('id')->limit(8)->get();
        $newConnections = CustomersInfo::where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->get(['created_at'])
            ->groupBy(fn ($customer) => Carbon::parse($customer->created_at)->format('Y-m'))
            ->map(function ($customers, $key) {
                [$year, $month] = array_map('intval', explode('-', $key));
                return (object) ['y' => $year, 'm' => $month, 'total' => $customers->count()];
            });
        $hotspotCustomers = HotspotSale::whereBetween('sale_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('username')->distinct()->count('username');
        $hotspotCardStock = \App\Models\HotspotVoucher::whereIn('status', ['unused', 'active'])->count();
        $attendanceTotal = \App\Models\User::count();
        $attendanceToday = 0;
        $deviceStats = [
            'routers_total' => \App\Models\RouterList::count(),
            'routers_online' => \App\Models\RouterList::where('action', 'connected')->count(),
            'olts_total' => (int) \App\Models\NetworkInventoryDevice::type('olt')->count(),
            'olts_online' => (int) \App\Models\NetworkInventoryDevice::type('olt')->where('status','online')->count(),
            'aps_total' => (int) \App\Models\NetworkInventoryDevice::type('access-point')->count(),
            'aps_online' => (int) \App\Models\NetworkInventoryDevice::type('access-point')->where('status','online')->count(),
        ];

        return view('dashboard', compact(
            'results', 'customersData', 'billInformationData', 'systemOverview', 'resellerData',
            'onlineNow', 'onlineCompany', 'onlineReseller', 'livePppSessions', 'livePppSessionsByRouter', 'livePppTelemetry', 'expired', 'lockedDisabled', 'runningDue', 'monthCollection', 'weekCollection',
            'todayCollection', 'mfsCollection', 'resellerDue', 'activeCustomerTotal', 'activeCompany', 'activeReseller',
            'billingToday', 'customersExpiringToday', 'customersExpiringTodayCount', 'autoDisableToday', 'autoDisableTodayCount', 'autoDisableResultsToday', 'autoDisableResultsTodayCount',
            'openTickets', 'overdueTickets', 'ticketsToday', 'pendingKyc', 'kycApprovedMonth', 'mfsPending',
            'recentTransactions', 'newConnections', 'hotspotCustomers', 'hotspotCardStock', 'attendanceTotal', 'attendanceToday', 'deviceStats', 'monthCollectionPppoe', 'monthCollectionHotspot', 'todayPppoe', 'todayHotspot', 'weekPppoe', 'weekHotspot'
        ));
    }
}
