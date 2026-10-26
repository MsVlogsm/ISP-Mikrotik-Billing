<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\PackageList;
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
        if ($status === 'online') {
            // Online Customers means a real MikroTik /ppp/active session, not an enabled account.
            // Always poll the live router sessions for this request. Do not reuse a cached
            // username list, because stale sessions can make disconnected customers appear online.
            $usersByRouter = [];
            $successCount = 0;
            $routerCount = RouterList::query()->count();
            $controller = app(\App\Http\Controllers\MikrotikController::class);
            foreach (RouterList::query()->get() as $router) {
                try {
                    $sessions = $controller->getActivePppSessions((string) $router->router_name);
                    // An empty response is a valid zero-session result; exceptions are not.
                    $successCount++;
                    foreach ($sessions as $session) {
                        $username = trim((string) ($session['name'] ?? ''));
                        if ($username !== '') $usersByRouter[(string) $router->router_name][mb_strtolower($username)] = true;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Online Customers live session read failed for '.$router->router_name.': '.$e->getMessage());
                }
            }
            $liveSessions = ['users' => $usersByRouter, 'successful_routers' => $successCount, 'router_count' => $routerCount];
            $onlineSessionStatus = ($liveSessions['successful_routers'] ?? 0) > 0 ? 'ok' : 'unavailable';
            // Shared predicate keeps this list and the Dashboard's Online Customers KPI consistent.
            app(\App\Services\OnlineCustomerSessionFilter::class)->apply(
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

        return view('xlink.broadband.customer-list', [
            'customers' => $customers,
            'mode' => $status ?: 'all',
            'routers' => RouterList::orderBy('router_name')->get(),
            'resellers' => Reseller::with('user')->get(),
            'packages' => PackageList::orderBy('package')->get(),
            'leftStats' => $leftStats,
            'onlineSessionStatus' => $onlineSessionStatus,
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
            $ip = $customer->pppUser?->ppp_remote_ip ?: $customer->pppUser?->ip_address;

            if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No valid customer IP is available for ping.',
                    'ip' => $ip,
                ], 422);
            }

            $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $binary = $isIpv6 ? 'ping6' : 'ping';
            $command = $binary.' -c 1 -W 2 '.escapeshellarg($ip);
            $startedAt = microtime(true);

            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($command, $descriptorSpec, $pipes);

            if (! is_resource($process)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Ping service could not be started.',
                    'ip' => $ip,
                ], 503);
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $latency = null;

            if (preg_match('/time[=<]([0-9.]+)\s*ms/i', (string) $stdout, $match)) {
                $latency = round((float) $match[1], 2);
            }

            if ($exitCode === 0) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Host is reachable.',
                    'ip' => $ip,
                    'latency_ms' => $latency ?? round((microtime(true) - $startedAt) * 1000, 2),
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => 'Host did not reply to ping.',
                'ip' => $ip,
                'latency_ms' => $latency,
                'detail' => trim((string) $stderr),
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
                'rx_bps' => (int) ($data['rx-bits-per-second'] ?? 0),
                'tx_bps' => (int) ($data['tx-bits-per-second'] ?? 0),
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
