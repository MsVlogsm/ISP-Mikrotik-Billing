<?php

namespace App\Http\Controllers;

use App\Models\CustomersInfo;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UniversalNetworkSearchController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'all');
        abort_unless(in_array($type, ['all', 'pppoe', 'ip', 'mac', 'serial', 'name'], true), 422);

        $submitted = $request->has('q') && $query !== '';
        $customers = collect();
        $pppUsers = collect();
        $onuMappings = collect();
        $onuLinks = collect();
        $liveSessions = collect();
        $arpEntries = collect();
        $routerChecks = [];

        if ($submitted) {
            if (in_array($type, ['all', 'name', 'pppoe'], true)) {
                $customers = CustomersInfo::query()
                    ->with(['pppUser', 'package'])
                    ->where(function ($builder) use ($query, $type): void {
                        if (in_array($type, ['all', 'name'], true)) {
                            $builder->where('customer_name', 'like', '%'.$query.'%')
                                ->orWhere('customer_unique_id', 'like', '%'.$query.'%')
                                ->orWhere('mobile', 'like', '%'.$query.'%');
                        }
                        if (in_array($type, ['all', 'pppoe'], true)) {
                            $method = in_array($type, ['all', 'name'], true) ? 'orWhereHas' : 'whereHas';
                            $builder->{$method}('pppUser', fn ($ppp) => $ppp->where('username', 'like', '%'.$query.'%'));
                        }
                    })
                    ->limit(30)
                    ->get();
            }

            if (in_array($type, ['all', 'pppoe', 'ip', 'mac'], true)) {
                $pppUsers = PPPSecrets::query()
                    ->with('customer')
                    ->where(function ($builder) use ($query, $type): void {
                        $columns = match ($type) {
                            'pppoe' => ['username'],
                            'ip' => ['ppp_remote_ip'],
                            'mac' => ['caller_id', 'last_caller_id'],
                            default => ['username', 'ppp_remote_ip', 'caller_id', 'last_caller_id'],
                        };
                        foreach ($columns as $index => $column) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $builder->{$method}($column, 'like', '%'.$query.'%');
                        }
                    })
                    ->limit(40)
                    ->get();
            }

            if (in_array($type, ['all', 'ip', 'mac', 'serial'], true)) {
                $onuMappings = OltOnuCustomerMapping::query()
                    ->with(['olt', 'customer', 'pppUser'])
                    ->where(function ($builder) use ($query, $type): void {
                        $columns = match ($type) {
                            'ip' => ['onu_ip'],
                            'mac' => ['onu_mac'],
                            'serial' => ['onu_serial'],
                            default => ['onu_ip', 'onu_mac', 'onu_serial', 'onu_id', 'pon_port'],
                        };
                        foreach ($columns as $index => $column) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $builder->{$method}($column, 'like', '%'.$query.'%');
                        }
                    })
                    ->limit(50)
                    ->get();

                if (in_array($type, ['all', 'mac'], true)) {
                    $onuLinks = OltOnuCustomerPppLink::query()
                        ->with(['customer', 'pppUser', 'onuMapping.olt'])
                        ->where('client_mac', 'like', '%'.$query.'%')
                        ->limit(50)
                        ->get();
                }
            }

            // Read active PPP sessions and ARP tables from connected RouterOS
            // devices only after a search is submitted. No router changes occur.
            if (in_array($type, ['all', 'pppoe', 'ip', 'mac'], true)) {
                $mikrotik = app(MikrotikController::class);
                $routers = RouterList::query()
                    ->where('action', 'connected')
                    ->where(function ($builder): void {
                        $builder->whereNotNull('api_port')->orWhereNotNull('ssh_port');
                    })
                    ->orderBy('router_name')
                    ->limit(15)
                    ->get();

                foreach ($routers as $router) {
                    $routerName = (string) $router->router_name;
                    $routerChecks[] = $routerName;

                    if (in_array($type, ['all', 'pppoe'], true)) {
                        try {
                            $sessions = $mikrotik->singleRead(
                                $routerName,
                                '/ppp/active/print',
                                '/ppp active print without-paging terse',
                                [],
                                false,
                                true
                            );
                            foreach ($sessions as $session) {
                                if (! is_array($session)) {
                                    continue;
                                }
                                $username = trim((string) ($session['name'] ?? $session['user'] ?? $session['username'] ?? ''));
                                $ip = trim((string) ($session['address'] ?? $session['remote-address'] ?? ''));
                                $mac = trim((string) ($session['caller-id'] ?? $session['caller_id'] ?? ''));
                                $haystack = mb_strtolower($username.' '.$ip.' '.$mac);
                                if ($username === '' || ! str_contains($haystack, mb_strtolower($query))) {
                                    continue;
                                }

                                $ppp = PPPSecrets::query()
                                    ->with(['customer'])
                                    ->where('router_name', $routerName)
                                    ->where('username', $username)
                                    ->first();

                                $liveSessions->push([
                                    'router' => $routerName,
                                    'username' => $username,
                                    'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                                    'mac' => $this->normalizeMac($mac),
                                    'customer' => $ppp?->customer?->customer_name,
                                    'customer_id' => $ppp?->customer?->customer_unique_id,
                                ]);
                            }
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    }

                    if (in_array($type, ['all', 'ip', 'mac'], true)) {
                        try {
                            $arpRows = $mikrotik->singleRead(
                                $routerName,
                                '/ip/arp/print',
                                '/ip arp print without-paging terse',
                                [],
                                false,
                                true
                            );
                            foreach ($arpRows as $arp) {
                                if (! is_array($arp)) {
                                    continue;
                                }
                                $ip = trim((string) ($arp['address'] ?? ''));
                                $mac = $this->normalizeMac((string) ($arp['mac-address'] ?? $arp['mac_address'] ?? ''));
                                $haystack = mb_strtolower($ip.' '.$mac);
                                if (! str_contains($haystack, mb_strtolower($query))) {
                                    continue;
                                }

                                $arpEntries->push([
                                    'router' => $routerName,
                                    'ip' => $ip,
                                    'mac' => $mac,
                                    'interface' => $arp['interface'] ?? null,
                                    'status' => $arp['status'] ?? null,
                                ]);
                            }
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    }
                }
            }
        }

        return view('xlink.universal-search', compact(
            'query',
            'type',
            'submitted',
            'customers',
            'pppUsers',
            'onuMappings',
            'onuLinks',
            'liveSessions',
            'arpEntries',
            'routerChecks'
        ));
    }

    private function normalizeMac(string $value): ?string
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', $value) ?? '';
        if (strlen($hex) !== 12) {
            return null;
        }

        return strtoupper(implode(':', str_split($hex, 2)));
    }
}
