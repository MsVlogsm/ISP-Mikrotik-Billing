<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\PackageList;
use App\Models\PaymentSummary;
use App\Models\CollectionSummary;
use App\Models\PPPSecrets;
use App\Models\Reseller;
use App\Models\RouterList;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BroadbandController extends Controller
{
    private function baseQuery(Request $request)
    {
        return CustomersInfo::query()
            ->with(['billing', 'pppUser', 'customerAddress', 'package', 'reseller'])
            ->when($request->q, fn ($q, $v) => $q->where(function ($x) use ($v) {
                $like = "%{$v}%";
                $x->where('customer_name', 'like', $like)
                  ->orWhere('customer_unique_id', 'like', $like)
                  ->orWhere('mobile', 'like', $like)
                  ->orWhere('alternative_mobile', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('contact_person', 'like', $like)
                  ->orWhere('parents_name', 'like', $like)
                  ->orWhere('spouse_name', 'like', $like)
                  ->orWhere('address', 'like', $like)
                  ->orWhere('identification_no', 'like', $like)
                  ->orWhere('profession', 'like', $like)
                  ->orWhere('zone_name', 'like', $like)
                  ->orWhere('server_label', 'like', $like)
                  ->orWhere('left_equipment', 'like', $like)
                  ->orWhere('left_reason', 'like', $like)
                  ->orWhereHas('customerAddress', function ($address) use ($like) {
                      $address->where('label_name', 'like', $like)
                          ->orWhere('input_type_text', 'like', $like)
                          ->orWhere('input_type_dropdown', 'like', $like)
                          ->orWhere('input_type_textarea', 'like', $like);
                  })
                  ->orWhereHas('pppUser', function ($ppp) use ($like) {
                      $ppp->where('username', 'like', $like)
                          ->orWhere('router_name', 'like', $like)
                          ->orWhere('ppp_remote_ip', 'like', $like)
                          ->orWhere('caller_id', 'like', $like)
                          ->orWhere('comment', 'like', $like);
                  })
                  ->orWhereHas('package', function ($package) use ($like) {
                      $package->where('package', 'like', $like);
                  });
            }))
            ->when($request->router_id, fn ($q, $v) => $q->whereHas('pppUser', fn ($x) => $x->where('router_name', $v)))
            ->when($request->reseller_id, fn ($q, $v) => $q->where('reseller_id', $v));
    }

    public function list(Request $request)
    {
        $status = $request->status;
        $query = $this->baseQuery($request);
        $leftStats = null;

        $onlineSessionStatus = null;
        $livePppDetails = [];
        if ($status === 'online') {
            // Online Customers means a real MikroTik /ppp/active session, not an enabled account.
            // Always poll the live router sessions for this request. Do not reuse a cached
            // username list, because stale sessions can make disconnected customers appear online.
            $usersByRouter = [];
            $successCount = 0;
            // Disconnected routers are not successful zero-session reads.
            $connectedRouters = RouterList::query()->where('action', 'connected')->get();
            $routerCount = $connectedRouters->count();
            $controller = app(\App\Http\Controllers\MikrotikController::class);
            foreach ($connectedRouters as $router) {
                try {
                    $sessions = $controller->getActivePppSessions((string) $router->router_name);
                    // An empty response is a valid zero-session result; exceptions are not.
                    $successCount++;
                    foreach ($sessions as $session) {
                        if (! is_array($session)) continue;
                        $username = trim((string) ($session['name'] ?? $session['user'] ?? $session['username'] ?? ''));
                        if ($username === '') continue;
                        $key = mb_strtolower($username);
                        $usersByRouter[(string) $router->router_name][$key] = true;
                        $address = $session['address'] ?? $session['remote-address'] ?? null;
                        $mac = $session['caller-id'] ?? $session['caller_id'] ?? null;
                        $livePppDetails[(string) $router->router_name][$key] = [
                            'ip' => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) ? $address : null,
                            'mac' => is_string($mac) && preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) ? strtoupper($mac) : null,
                        ];
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Online Customers live session read failed for '.$router->router_name.': '.$e->getMessage());
                }
            }
            $liveSessions = ['users' => $usersByRouter, 'successful_routers' => $successCount, 'router_count' => $routerCount];
            $onlineSessionFilter = app(\App\Services\OnlineCustomerSessionFilter::class);
            $liveSessions['users'] = $onlineSessionFilter->addDisconnectedRouterAliases(
                $liveSessions['users'],
                (int) $liveSessions['successful_routers']
            );
            $onlineSessionStatus = ($liveSessions['successful_routers'] ?? 0) > 0 ? 'ok' : 'unavailable';
            // Shared predicate keeps this list and the Dashboard's Online Customers KPI consistent.
            $onlineSessionFilter->apply(
                $query,
                $liveSessions['users'],
                (int) $liveSessions['successful_routers']
            );
        }

        if ($status === 'inactive') {
            $query->whereIn('status', ['inactive', 'disable']);

            $recoveryStatuses = ['pending', 'partial', 'recovered', 'not_required', 'issue'];
            if (in_array($request->input('recovery_status'), $recoveryStatuses, true)) {
                $query->where('recovery_status', $request->input('recovery_status'));
            }

            if ($request->filled('zone_name')) {
                $query->where('zone_name', 'like', '%'.trim($request->input('zone_name')).'%');
            }

            if ($request->filled('left_from')) {
                $query->whereDate('left_date', '>=', $request->input('left_from'));
            }
            if ($request->filled('left_to')) {
                $query->whereDate('left_date', '<=', $request->input('left_to'));
            }

            $allLeft = CustomersInfo::query()->whereIn('status', ['inactive', 'disable']);
            $leftStats = [
                'total' => (clone $allLeft)->count(),
                'pending_recovery' => (clone $allLeft)->where(function ($q) {
                    $q->whereNull('recovery_status')->orWhereIn('recovery_status', ['pending', 'partial']);
                })->count(),
                'recovered' => (clone $allLeft)->where('recovery_status', 'recovered')->count(),
                'issues' => (clone $allLeft)->where('recovery_status', 'issue')->count(),
            ];
        }

        if ($status === 'unverified') $query->where(function ($q) { $q->whereNull('mobile')->orWhereNull('identification_no'); });
        if ($status === 'due') $query->whereHas('billing', fn ($q) => $q->where('due_amount', '>', 0));
        $paidMonth = null;
        if ($status === 'paid') {
            // BillingInfo is rolled forward at the start of each billing cycle and
            // paid_amount is reset for the new month. Historical paid customers must
            // therefore be calculated from that month's PaymentSummary + collections,
            // not from the newly-reset BillingInfo row.
            $requestedMonth = (string) $request->input('paid_month', '');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth)) {
                $paidMonth = $requestedMonth;
            } else {
                $latestPaidDate = CollectionSummary::query()
                    ->where('payment_status', 'paid')
                    ->where('collection_amount', '>', 0)
                    ->max('collection_date');
                $paidMonth = $latestPaidDate
                    ? Carbon::parse($latestPaidDate)->format('Y-m')
                    : now()->format('Y-m');
            }

            $monthStart = Carbon::createFromFormat('!Y-m', $paidMonth)->startOfMonth();
            $monthEnd = $monthStart->copy()->addMonth();
            $monthStartValue = $monthStart->toDateString();
            $monthEndValue = $monthEnd->toDateString();

            $settledCustomerIds = PaymentSummary::query()
                ->whereDate('summary_date', $monthStartValue)
                ->whereRaw('(COALESCE(monthly_rent,0) + COALESCE(additional_charge,0) + COALESCE(vat,0) + COALESCE(previous_due,0) - COALESCE(discount,0) - COALESCE(advance,0)) > 0')
                ->whereRaw(
                    '(SELECT COALESCE(SUM(cs.collection_amount),0) FROM collection_summaries cs '
                    .'WHERE cs.customer_collection_unique_id = payment_summaries.customer_payment_unique_id '
                    .'AND cs.payment_status = ? AND cs.collection_date >= ? AND cs.collection_date < ?) '
                    .'>= (COALESCE(monthly_rent,0) + COALESCE(additional_charge,0) + COALESCE(vat,0) + COALESCE(previous_due,0) - COALESCE(discount,0) - COALESCE(advance,0))',
                    ['paid', $monthStartValue, $monthEndValue]
                )
                ->whereExists(function ($subquery) {
                    $subquery->selectRaw('1')
                        ->from('customers_infos as paid_customers')
                        ->whereColumn('paid_customers.customer_unique_id', 'payment_summaries.customer_payment_unique_id')
                        ->whereNull('paid_customers.deleted_at');
                })
                ->select('customer_payment_unique_id');

            $query->whereIn('customer_unique_id', $settledCustomerIds);
        }
        if ($status === 'disabled') $query->whereIn('status', ['disable', 'temporary_disable']);

        if ($status === 'inactive') {
            // Left-customer date filters use the recorded departure date, not the
            // account creation date. Other list modes keep their created_at filters.
            if ($request->filled('from')) $query->whereDate('left_date', '>=', $request->input('from'));
            if ($request->filled('to')) $query->whereDate('left_date', '<=', $request->input('to'));
        } else {
            if ($request->from) $query->whereDate('created_at', '>=', $request->from);
            if ($request->to) $query->whereDate('created_at', '<=', $request->to);
        }

        $rows = min(max((int) $request->input('rows', 50), 10), 500);
        $customers = $query->latest('id')->paginate($rows)->withQueryString();

        // Attach router-reported PPPoE IP/MAC to each online customer by the exact
        // router + username pair. Never infer an active IP from a pool name or stale IP.
        foreach ($customers as $customer) {
            $ppp = $customer->pppUser;
            $routerName = trim((string) ($ppp?->router_name ?? ''));
            $username = mb_strtolower(trim((string) ($ppp?->username ?? '')));
            $live = $livePppDetails[$routerName][$username] ?? [];
            $customer->setAttribute('live_ppp_ip', $live['ip'] ?? null);
            $customer->setAttribute('live_ppp_mac', $live['mac'] ?? null);
        }

        // Join ONU optical readings only through the saved Customer ID mapping.
        // A reading is considered current only when its OPM timestamp is <= 30 minutes old.
        $pageCustomerIds = $customers->getCollection()->pluck('id')->filter()->unique()->values();
        $opticalByCustomer = $pageCustomerIds->isNotEmpty()
            ? \App\Models\OltOnuCustomerMapping::with('olt:id,name')
                ->whereIn('customer_id', $pageCustomerIds)
                ->whereNotNull('opm_last_seen_at')
                ->where('opm_last_seen_at', '>=', now()->subMinutes(30))
                ->whereNotNull('rx_power')
                ->orderByDesc('opm_last_seen_at')
                ->get(['id','olt_device_id','customer_id','onu_id','onu_mac','pon_port','rx_power','tx_power','opm_last_seen_at','status'])
                ->unique('customer_id')->keyBy('customer_id')
            : collect();

        foreach ($customers as $customer) {
            $optical = $opticalByCustomer->get($customer->id);
            $customer->setAttribute('live_onu_rx_power', $optical?->rx_power !== null ? (float) $optical->rx_power : null);
            $customer->setAttribute('live_onu_tx_power', $optical?->tx_power !== null ? (float) $optical->tx_power : null);
            $customer->setAttribute('live_onu_id', $optical?->onu_id);
            $customer->setAttribute('live_onu_mac', $optical?->onu_mac);
            $customer->setAttribute('live_onu_pon', $optical?->pon_port);
            $customer->setAttribute('live_onu_olt_name', $optical?->olt?->name);
            $customer->setAttribute('live_onu_opm_at', $optical?->opm_last_seen_at);
        }

        return view('xlink.broadband.customer-list', [
            'customers' => $customers,
            'mode' => $status ?: 'all',
            'routers' => RouterList::orderBy('router_name')->get(),
            'resellers' => Reseller::with('user')->get(),
            'packages' => PackageList::orderBy('package')->get(),
            'leftStats' => $leftStats,
            'onlineSessionStatus' => $onlineSessionStatus,
            'paidMonth' => $paidMonth,
        ]);
    }

    public function search(Request $request)
    {
        $customers = $this->baseQuery($request)->latest('id')->paginate(50)->withQueryString();
        return view('xlink.broadband.search', compact('customers'));
    }

    public function due(Request $request)
    { $request->merge(['status' => 'due']); return $this->list($request); }
    public function inactive(Request $request)
    { $request->merge(['status' => 'inactive']); return $this->list($request); }
    public function newCustomers(Request $request)
    { $request->merge(['from' => Carbon::now()->toDateString()]); return $this->list($request); }
    public function unverified(Request $request)
    { $request->merge(['status' => 'unverified']); return $this->list($request); }

    public function customerPing(string $id): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['mikrotik-setup']), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)->with('pppUser')->firstOrFail();
            // PPPoE addresses are dynamic: always resolve the current address from
            // the matching MikroTik active session. Never ping a stored/stale IP.
            $ppp = $customer->pppUser;
            $username = trim((string) ($ppp?->username ?? ''));
            $routerName = trim((string) ($ppp?->router_name ?? ''));
            if ($username === '' || $routerName === '') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Customer PPPoE username or router is not configured.',
                ], 422);
            }

            $sessions = app(MikrotikController::class)->singleRead(
                $routerName,
                '/ppp/active/print',
                '/ppp active print without-paging terse',
                [],
                false,
                true
            );
            $ip = null;
            foreach ($sessions as $session) {
                if (! is_array($session)) {
                    continue;
                }
                $activeUsername = trim((string) ($session['name'] ?? $session['user'] ?? $session['username'] ?? ''));
                if ($activeUsername !== $username) {
                    continue;
                }
                $candidate = $session['address'] ?? $session['remote-address'] ?? null;
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break;
                }
            }

            if (! $ip) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Customer has no active PPPoE session on the assigned MikroTik router. Reconnect the customer or check router connectivity.',
                    'username' => $username,
                    'router' => $routerName,
                ], 422);
            }

            // PPPoE pool IPs may be private and unreachable from the billing host.
            // Run the ICMP probe from the customer's assigned MikroTik router instead.
            $startedAt = microtime(true);
            $pingRows = app(MikrotikController::class)->singleRead(
                $routerName,
                '/ping',
                '/ping address='.$ip.' count=1',
                ['address' => $ip, 'count' => 1],
                false,
                true
            );
            $pingResult = is_array($pingRows[0] ?? null) ? $pingRows[0] : $pingRows;
            $received = (int) ($pingResult['received'] ?? 0);
            $latency = null;
            $rtt = $pingResult['avg-rtt'] ?? $pingResult['time'] ?? null;

            if (is_numeric($rtt)) {
                $latency = round((float) $rtt, 2);
            } elseif (is_string($rtt) && preg_match('/^(?:(\d+(?:\.\d+)?)ms)?(?:(\d+(?:\.\d+)?)us)?$/i', trim($rtt), $match)
                && (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '')) {
                $latency = round((float) ($match[1] ?? 0) + ((float) ($match[2] ?? 0) / 1000), 2);
            }

            if ($received > 0) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Host is reachable from the assigned MikroTik router.',
                    'ip' => $ip,
                    'latency_ms' => $latency ?? round((microtime(true) - $startedAt) * 1000, 2),
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => 'Host did not reply to ping from the assigned MikroTik router.',
                'ip' => $ip,
                'latency_ms' => $latency,
                'detail' => (string) ($pingResult['status'] ?? 'No ICMP reply received.'),
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Ping check failed.',
            ], 500);
        }
    }

    public function customerTraffic(string $id): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['mikrotik-setup']), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)
                ->with('pppUser')
                ->firstOrFail();

            $ppp = $customer->pppUser;

            if (! $ppp || ! $ppp->router_name || ! $ppp->username) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No active MikroTik PPP session information is available.',
                ], 422);
            }

            if ($customer->status !== 'active' || $ppp->status !== 'active') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Customer is no longer online.',
                ], 409);
            }

            $interface = '<pppoe-'.$ppp->username.'>';
            $data = app(MikrotikController::class)->getLiveTraffic($ppp->router_name, $interface);

            return response()->json([
                'ok' => true,
                'customer' => $customer->customer_unique_id,
                'username' => $ppp->username,
                'router' => $ppp->router_name,
                'interface' => $interface,
                // RouterOS RX/TX is from the router's interface perspective:
                // PPPoE TX is customer download; PPPoE RX is customer upload.
                'rx_bps' => (int) ($data['rx-bits-per-second'] ?? 0),
                'tx_bps' => (int) ($data['tx-bits-per-second'] ?? 0),
                'download_bps' => (int) ($data['tx-bits-per-second'] ?? 0),
                'upload_bps' => (int) ($data['rx-bits-per-second'] ?? 0),
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Live traffic check failed.',
            ], 500);
        }
    }

    public function disableCustomer(Request $request, string $id)
    {
        abort_unless(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['disable-customer']), 403);
        $request->validate(['left_reason' => 'nullable|string|max:2000']);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)->with('pppUser')->firstOrFail();
            $pppUser = $customer->pppUser;
            $pendingAction = null;

            if ($pppUser && filled($pppUser->router_name) && filled($pppUser->username)) {
                $routerName = (string) $pppUser->router_name;
                $routerConnected = RouterList::where('router_name', $routerName)
                    ->where('action', 'connected')
                    ->exists();

                if ($routerConnected) {
                    try {
                        app(MikrotikController::class)->disablePPPSecret($uniqueId, $routerName, $pppUser->username);
                    } catch (\Throwable $routerError) {
                        report($routerError);
                        $routerConnected = false;
                    }
                }

                if (! $routerConnected) {
                    $pendingAction = [
                        'customer_unique_id' => $uniqueId,
                        'router_name' => $routerName,
                        'username' => $pppUser->username,
                        'action' => 'disable',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customer, $request, $pendingAction, $uniqueId) {
                $pendingId = null;
                $pendingQueue = app(\App\Services\MikrotikPendingActionQueue::class);
                if ($customer->pppUser && filled($customer->pppUser->router_name) && filled($customer->pppUser->username)) {
                    $pendingQueue->supersedePending($uniqueId, $customer->pppUser->router_name, $customer->pppUser->username, 'disable');
                    if ($pendingAction) {
                        $pendingId = $pendingQueue->queue($uniqueId, $customer->pppUser->router_name, $customer->pppUser->username, 'disable');
                    }
                }

                $customer->status = 'disable';
                $customer->left_date = now();
                $customer->left_reason = filled($request->input('left_reason'))
                    ? trim($request->input('left_reason'))
                    : $customer->left_reason;
                $customer->server_label = $customer->server_label ?: ($customer->pppUser?->router_name ?: 'X-Link Internet');
                $customer->recovery_status = $customer->recovery_status ?: 'pending';
                $customer->save();

                if ($customer->pppUser) {
                    PPPSecrets::whereKey($customer->ppp_user_id)->update(['status' => 'disable']);
                }

                return $pendingId;
            });

            if ($pendingId) {
                \Log::warning('Broadband customer disabled locally; remote action queued for unavailable router', [
                    'customer_id' => $uniqueId,
                    'router_name' => $pendingAction['router_name'],
                    'username' => $pendingAction['username'],
                    'pending_action_id' => $pendingId,
                ]);
            }

            return back()->with('broadband_message', $pendingId
                ? 'Customer disabled locally. Router action queued until the router is available.'
                : 'Customer disabled successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['customer' => 'Failed to disable customer. Please try again.']);
        }
    }

    public function updateLeftDetails(Request $request, string $id)
    {
        abort_unless(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['edit-customer']), 403);
        $data = $request->validate([
            'zone_name' => 'nullable|string|max:120',
            'left_equipment' => 'nullable|string|max:5000',
            'recovery_status' => 'required|in:pending,partial,recovered,not_required,issue',
            'left_reason' => 'nullable|string|max:2000',
            'server_label' => 'nullable|string|max:160',
            'left_date' => 'nullable|date',
        ]);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)->firstOrFail();
            $customer->fill($data);
            $customer->save();
            return back()->with('broadband_message', 'Left customer details updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['customer' => 'Could not update Left Customer details.']);
        }
    }

    public function destroyCustomer(string $id)
    {
        abort_unless(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['delete-customer']), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)->with('pppUser')->firstOrFail();

            if ($customer->status === 'active') {
                return back()->withErrors(['customer' => 'Disable the customer before deleting the customer record.']);
            }

            $pppUser = $customer->pppUser;
            $pendingAction = null;

            if ($pppUser && filled($pppUser->router_name) && filled($pppUser->username)) {
                $routerName = (string) $pppUser->router_name;
                $routerConnected = RouterList::where('router_name', $routerName)
                    ->where('action', 'connected')
                    ->exists();

                if ($routerConnected) {
                    try {
                        app(MikrotikController::class)->removePPPSecret($uniqueId, $routerName, $pppUser->username);
                    } catch (\Throwable $routerError) {
                        report($routerError);
                        $routerConnected = false;
                    }
                }

                if (! $routerConnected) {
                    $pendingAction = [
                        'customer_unique_id' => $uniqueId,
                        'router_name' => $routerName,
                        'username' => $pppUser->username,
                        'action' => 'remove',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customer, $pppUser, $pendingAction, $uniqueId) {
                $pendingId = null;
                $pendingQueue = app(\App\Services\MikrotikPendingActionQueue::class);
                if ($pppUser && filled($pppUser->router_name) && filled($pppUser->username)) {
                    $pendingQueue->supersedePending($uniqueId, $pppUser->router_name, $pppUser->username, 'remove');
                    if ($pendingAction) {
                        $pendingId = $pendingQueue->queue($uniqueId, $pppUser->router_name, $pppUser->username, 'remove');
                    }
                }

                if ($pppUser) {
                    $pppUser->delete();
                }
                $customer->delete();

                return $pendingId;
            });

            if ($pendingId) {
                \Log::warning('Broadband customer deleted locally; remote removal queued for unavailable router', [
                    'customer_id' => $uniqueId,
                    'router_name' => $pendingAction['router_name'],
                    'username' => $pendingAction['username'],
                    'pending_action_id' => $pendingId,
                ]);
            }

            return redirect()->route('broadband-customers')->with(
                'broadband_message',
                $pendingId
                    ? 'Customer deleted locally. Router removal queued until the router is available.'
                    : 'Customer deleted successfully.'
            );
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['customer' => 'Failed to delete customer. Please try again.']);
        }
    }


    public function packages(Request $request)
    {
        $packages = PackageList::query()->orderBy('package')->paginate(50)->withQueryString();
        return view('xlink.broadband.packages', compact('packages'));
    }

    public function import()
    {
        return view('xlink.broadband.import', [
            'routers' => RouterList::orderBy('router_name')->get(),
            'packages' => PackageList::orderBy('package')->get(),
        ]);
    }

    public function storePackage(Request $request)
    {
        $data = $request->validate([
            'package' => 'required|string|max:120',
            'price' => 'required|numeric|min:0',
        ]);
        PackageList::create($data + ['status' => 'active']);

        return back()->with('broadband_message', 'Package created successfully.');
    }

}
