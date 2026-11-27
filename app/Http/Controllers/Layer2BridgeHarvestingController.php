<?php

namespace App\Http\Controllers;

use App\Models\MikrotikBridgeHostMovement;
use App\Models\MikrotikBridgeHostSnapshot;
use App\Models\NetworkBridgeFdbMovement;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\RouterList;
use Illuminate\Http\Request;
use Illuminate\View\View;

class Layer2BridgeHarvestingController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $vlan = trim((string) $request->query('vlan', ''));
        $sourceFilter = trim((string) $request->query('source', ''));
        $sources = [];
        $entries = collect();
        $movements = collect();

        $devices = NetworkInventoryDevice::query()
            ->whereIn('type', ['olt', 'switch'])
            ->orderBy('type')->orderBy('name')->get();

        foreach ($devices as $device) {
            $latestAttempt = NetworkBridgeFdbSnapshot::query()
                ->where('network_inventory_device_id', $device->id)->latest('sampled_at')->first();
            $snapshot = NetworkBridgeFdbSnapshot::query()
                ->where('network_inventory_device_id', $device->id)
                ->whereIn('status', ['ok', 'empty'])->latest('sampled_at')->first();

            $sources[] = [
                'key' => 'snmp-'.$device->id,
                'type' => ucfirst($device->type),
                'device' => $device->name,
                'address' => $device->ip_address ?: $device->host,
                'status' => $latestAttempt?->status ?? 'never',
                'sampled_at' => $snapshot?->sampled_at,
                'entries' => (int) ($snapshot?->entry_count ?? 0),
                'error' => $latestAttempt && ! in_array($latestAttempt->status, ['ok', 'empty'], true) ? $latestAttempt->error_message : null,
            ];

            if ($snapshot) {
                foreach ($snapshot->entries()->orderBy('mac_address')->limit(500)->get() as $entry) {
                    $entries->push([
                        'source' => 'OLT/Switch SNMP', 'device' => $device->name, 'type' => ucfirst($device->type),
                        'mac' => $entry->mac_address, 'vlan' => $entry->vlan_id,
                        'bridge' => null, 'port' => $entry->interface_name ?: ($entry->if_index ? 'ifIndex '.$entry->if_index : '—'),
                        'status' => $entry->entry_status, 'sampled_at' => $snapshot->sampled_at,
                    ]);
                }
            }

            $deviceMovements = NetworkBridgeFdbMovement::query()
                ->where('network_inventory_device_id', $device->id)->latest('moved_at')->limit(100)->get();
            foreach ($deviceMovements as $movement) {
                $movements->push([
                    'source' => 'OLT/Switch SNMP', 'device' => $device->name, 'mac' => $movement->mac_address,
                    'vlan' => $movement->vlan_id, 'from' => $movement->from_interface, 'to' => $movement->to_interface,
                    'moved_at' => $movement->moved_at, 'moves' => $movement->moves_in_10m, 'flapping' => $movement->is_flapping,
                ]);
            }
        }

        $routers = RouterList::query()->orderBy('router_name')->get(['id', 'router_name', 'action']);
        foreach ($routers as $router) {
            $latestAttempt = MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $router->id)->latest('sampled_at')->first();
            $snapshot = MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $router->id)->whereIn('status', ['ok', 'empty'])->latest('sampled_at')->first();

            $sources[] = [
                'key' => 'routeros-'.$router->id, 'type' => 'MikroTik', 'device' => $router->router_name,
                'address' => null, 'status' => $latestAttempt?->status ?? 'never',
                'sampled_at' => $snapshot?->sampled_at, 'entries' => (int) ($snapshot?->entry_count ?? 0),
                'error' => $latestAttempt && ! in_array($latestAttempt->status, ['ok', 'empty'], true) ? $latestAttempt->error_message : null,
            ];

            if ($snapshot) {
                foreach ($snapshot->entries()->orderBy('mac_address')->limit(500)->get() as $entry) {
                    $entries->push([
                        'source' => 'MikroTik RouterOS API', 'device' => $router->router_name, 'type' => 'MikroTik',
                        'mac' => $entry->mac_address, 'vlan' => $entry->vlan_id, 'bridge' => $entry->bridge_name,
                        'port' => $entry->interface_name ?: '—', 'status' => $entry->is_dynamic ? 'dynamic' : 'static',
                        'sampled_at' => $snapshot->sampled_at,
                    ]);
                }
            }

            foreach (MikrotikBridgeHostMovement::query()->where('router_list_id', $router->id)->latest('moved_at')->limit(100)->get() as $movement) {
                $movements->push([
                    'source' => 'MikroTik RouterOS API', 'device' => $router->router_name, 'mac' => $movement->mac_address,
                    'vlan' => $movement->vlan_id, 'from' => $movement->from_interface, 'to' => $movement->to_interface,
                    'moved_at' => $movement->moved_at, 'moves' => $movement->moves_in_10m, 'flapping' => $movement->is_flapping,
                ]);
            }
        }

        if ($sourceFilter !== '') {
            $entries = $entries->filter(fn (array $entry): bool => str_contains(strtolower($entry['source']), strtolower($sourceFilter)));
        }
        if ($vlan !== '' && ctype_digit($vlan)) {
            $entries = $entries->filter(fn (array $entry): bool => (string) $entry['vlan'] === $vlan);
        }
        if ($search !== '') {
            $entries = $entries->filter(fn (array $entry): bool => str_contains(strtolower(implode(' ', [$entry['mac'], $entry['device'], $entry['port'], $entry['bridge'] ?? ''])), strtolower($search)));
        }

        $sources = collect($sources)->sortBy(['type', 'device'])->values();
        $movements = $movements->sortByDesc('moved_at')->take(100)->values();

        return view('xlink.layer2-bridge-harvesting', [
            'sources' => $sources, 'entries' => $entries->sortBy(['device', 'mac'])->values(),
            'movements' => $movements, 'search' => $search, 'vlan' => $vlan, 'sourceFilter' => $sourceFilter,
            'stats' => [
                'sources' => $sources->count(), 'healthy' => $sources->whereIn('status', ['ok', 'empty'])->count(),
                'entries' => $entries->count(), 'flapping' => $movements->where('flapping', true)->count(),
            ],
        ]);
    }
}
