<?php
namespace App\Livewire;
use App\Models\CustomersAddress;
use App\Models\CustomersInfo;
use App\Models\RouterList;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkTopologyNode;
use App\Models\MainSiteData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
class NetworkMap extends Component
{
    public function mount(): void { if (! hasAccess(['Super Admin'], ['mikrotik-setup'])) abort(403); }
    public function render()
    {
        $routers = RouterList::orderBy('router_name')->get();
        $routerNodes = $routers
            ->filter(fn ($r) => $r->latitude !== null && $r->longitude !== null)
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'id' => $r->id,
                'label' => $r->router_name,
                'ip' => $r->ip_address,
                'location' => $r->location,
                'status' => $this->normalizeStatus($r->action === 'connected' ? 'online' : 'offline'),
                'health_status' => $r->action ?: 'unknown',
                'last_checked_at' => $r->last_checked_at ? (string) $r->last_checked_at : null,
                'last_latency_ms' => $r->last_latency_ms,
                'kind' => 'router',
                'router' => $r->router_name,
            ])->values()->toArray();

        $inventoryDevices = NetworkInventoryDevice::query()
            ->whereIn('type', ['olt', 'onu', 'switch', 'wifi-router', 'access-point'])
            ->get();
        $deviceLocations = $inventoryDevices->map(function ($d) {
            $lat = $d->latitude;
            $lng = $d->longitude;
            if (($lat === null || $lng === null) && is_string($d->location) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $d->location, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            }
            if ($lat === null || $lng === null) {
                return null;
            }

            return [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'id' => $d->id,
                'label' => $d->name,
                'status' => $this->normalizeStatus($d->health_status ?: ($d->status ?: 'unknown')),
                'health_status' => $d->health_status ?: ($d->status ?: 'unknown'),
                'router' => null,
                'kind' => $d->type,
                'ip' => $d->ip_address ?: $d->host,
                'location' => $d->location,
                'vendor' => $d->vendor,
                'model' => $d->model,
                'firmware' => $d->firmware,
                'serial_no' => $d->serial_no,
                'last_checked_at' => $d->last_checked_at?->format('Y-m-d H:i:s'),
                'last_latency_ms' => $d->last_latency_ms,
                'onu_total' => $d->onu_total,
                'onu_online' => $d->onu_online,
                'rx_power' => $d->rx_power,
                'customer_count' => $d->customer_count,
            ];
        })->filter()->values()->toArray();

        $publishedCustomNodes = NetworkTopologyNode::query()
            ->where('is_published', true)
            ->get();
        $topologyLocations = $publishedCustomNodes->filter(
            fn ($node) => $node->latitude !== null && $node->longitude !== null
        )->map(fn ($node) => [
            'lat' => (float) $node->latitude,
            'lng' => (float) $node->longitude,
            'id' => $node->id,
            'map_key' => 'topology:'.$node->id,
            'label' => $node->name,
            'status' => 'unknown',
            'health_status' => 'manual topology asset; live status not connected',
            'kind' => $node->type,
            'asset_type' => $node->type,
            'location' => $node->location,
            'notes' => $node->notes,
        ])->values()->toArray();

        $customers = CustomersInfo::whereNull('deleted_at')->count();

        // Queue only customers that do not have a complete, valid Network Location.
        // Coordinates are entered by an operator in the existing Customer Edit map;
        // no approximate or generated GPS values are introduced here.
        $unlocatedCustomerRecords = CustomersInfo::query()
            ->whereNull('customers_infos.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('customers_addresses as ca')
                    ->whereColumn('ca.customer_address_unique_id', 'customers_infos.customer_unique_id')
                    ->where('ca.label_name', 'Network Location')
                    ->whereNotNull('ca.latitude')
                    ->whereNotNull('ca.longitude')
                    ->where('ca.latitude', '!=', '')
                    ->where('ca.longitude', '!=', '')
                    ->whereBetween('ca.latitude', [-90, 90])
                    ->whereBetween('ca.longitude', [-180, 180]);
            })
            ->orderBy('customers_infos.customer_unique_id')
            ->limit(100)
            ->get([
                'customers_infos.customer_unique_id',
                'customers_infos.customer_name',
            ]);

        $nodes = CustomersAddress::query()
            ->leftJoin('customers_infos as ci', 'ci.customer_unique_id', '=', 'customers_addresses.customer_address_unique_id')
            ->leftJoin('p_p_p_secrets as ps', 'ps.id', '=', 'ci.ppp_user_id')
            ->where('customers_addresses.label_name', 'Network Location')
            ->whereNull('ci.deleted_at')
            ->whereNotNull('ci.customer_unique_id')
            ->whereNotNull('customers_addresses.latitude')
            ->whereNotNull('customers_addresses.longitude')
            ->where('customers_addresses.latitude', '!=', '')
            ->where('customers_addresses.longitude', '!=', '')
            ->whereBetween('customers_addresses.latitude', [-90, 90])
            ->whereBetween('customers_addresses.longitude', [-180, 180])
            ->limit(1000)
            ->get([
                'customers_addresses.latitude', 'customers_addresses.longitude',
                'customers_addresses.customer_address_unique_id', 'customers_addresses.label_name',
                'ci.customer_name', 'ci.status as customer_status', 'ps.router_name',
            ])
            ->unique('customer_address_unique_id')
            ->map(fn ($a) => [
                'lat' => (float) $a->latitude,
                'lng' => (float) $a->longitude,
                'id' => $a->customer_address_unique_id,
                'label' => $a->customer_name ?: $a->customer_address_unique_id,
                'customer_uid' => $a->customer_address_unique_id,
                'customer_url' => route('customers.show', ['id' => $a->customer_address_unique_id]),
                // Active billing status is not proof of a live network session.
                'status' => 'unknown',
                'account_status' => $a->customer_status,
                'router' => $a->router_name,
                'kind' => 'customer',
            ])->values()->toArray();

        // Build ONU markers from the real OLT ↔ customer mapping ledger.
        // ONU coordinates are represented by the linked customer's saved Network Location;
        // no synthetic coordinates or optical distance are generated.
        $customerLocations = collect($nodes)->where('kind', 'customer')->keyBy('customer_uid');
        $onuMappings = OltOnuCustomerMapping::with(['olt', 'customer', 'pppUser'])
            ->whereNotNull('customer_id')->latest('last_seen_at')->get();
        $onuNodes = $onuMappings->map(function ($mapping) use ($customerLocations) {
            $customerUid = $mapping->customer?->customer_unique_id;
            $customerLocation = $customerUid ? $customerLocations->get($customerUid) : null;
            if (! $customerLocation) return null;
            $olt = $mapping->olt;
            return [
                'lat' => $customerLocation['lat'], 'lng' => $customerLocation['lng'],
                'id' => $mapping->id, 'map_key' => 'onu:'.$mapping->id,
                'label' => 'ONU '.($mapping->onu_id ?: $mapping->onu_serial ?: $mapping->id),
                'status' => $this->normalizeStatus($mapping->status),
                'health_status' => $mapping->status ?: 'unknown', 'kind' => 'onu',
                'customer_uid' => $customerUid,
                'customer_url' => route('customers.show', ['id' => $customerUid]),
                'customer_name' => $mapping->customer?->customer_name,
                'router' => $mapping->pppUser?->router_name,
                'olt_id' => $mapping->olt_device_id, 'olt_name' => $olt?->name,
                'pon_port' => $mapping->pon_port, 'onu_id' => $mapping->onu_id,
                'onu_serial' => $mapping->onu_serial, 'onu_mac' => $mapping->onu_mac,
                'onu_ip' => $mapping->onu_ip, 'rx_power' => $mapping->rx_power,
                'tx_power' => $mapping->tx_power, 'last_seen_at' => $mapping->last_seen_at?->toIso8601String(),
                'location' => $customerLocation['location'] ?? null,
            ];
        })->filter()->values()->all();

        $mappedCustomers = count($nodes);
        $unmappedCustomers = max(0, $customers - $mappedCustomers);
        $unmappedRouters = max(0, $routers->count() - count($routerNodes));
        $unmappedDevices = max(0, $inventoryDevices->count() - count($deviceLocations));

        $nodes = collect(array_merge($nodes, $routerNodes, $deviceLocations, $topologyLocations, $onuNodes));
        $coordinates = $nodes->keyBy(fn ($node) => $node['map_key'] ?? (($node['kind'] === 'router' ? 'router:' : 'device:') . $node['id']));
        $publishedConnections = NetworkTopologyLink::query()->where('is_published', true)->get();
        $mapEdges = $publishedConnections->map(function ($link) use ($coordinates) {
            if (! $coordinates->has($link->source_key) || ! $coordinates->has($link->target_key)) {
                return null;
            }
            $source = $coordinates->get($link->source_key);
            $target = $coordinates->get($link->target_key);

            return [
                'fromKey' => $link->source_key,
                'toKey' => $link->target_key,
                'from' => [(float) $source['lat'], (float) $source['lng']],
                'to' => [(float) $target['lat'], (float) $target['lng']],
                'type' => $link->connection_type,
                'label' => $link->label ?: str_replace('_', ' ', $link->connection_type),
                'status' => $link->status ?: 'unknown',
                'capacity_mbps' => $link->capacity_mbps,
                'traffic_mbps' => $link->traffic_mbps,
                'latency_ms' => $link->latency_ms,
                'packet_loss' => $link->packet_loss,
                'fiber_core' => $link->fiber_core,
                'fiber_type' => $link->fiber_type,
                'source_port' => $link->source_port,
                'target_port' => $link->target_port,
            ];
        })->filter()->values()->toArray();

        // Draw only real OLT-to-ONU mappings where both endpoint coordinates exist.
        $oltOnuEdges = collect($onuNodes)->map(function ($onu) use ($coordinates) {
            $oltKey = 'device:'.$onu['olt_id'];
            if (! $coordinates->has($oltKey)) return null;
            $olt = $coordinates->get($oltKey);
            return [
                'fromKey' => $oltKey, 'toKey' => $onu['map_key'],
                'from' => [(float) $olt['lat'], (float) $olt['lng']],
                'to' => [(float) $onu['lat'], (float) $onu['lng']],
                'type' => 'fiber_core',
                'label' => trim(($onu['olt_name'] ?: 'OLT').' / PON '.($onu['pon_port'] ?: '—').' → '.$onu['label']),
                'status' => $onu['status'], 'fiber_core' => null, 'fiber_type' => 'unknown',
                'rx_power' => $onu['rx_power'], 'tx_power' => $onu['tx_power'],
                'onu_id' => $onu['onu_id'], 'onu_mac' => $onu['onu_mac'],
            ];
        })->filter()->values()->all();
        $mapEdges = array_merge($mapEdges, $oltOnuEdges);

        $mapStats = [
            'total_nodes' => $nodes->count(),
            'online_nodes' => $nodes->where('status', 'online')->count(),
            'offline_nodes' => $nodes->where('status', 'offline')->count(),
            'unknown_nodes' => $nodes->where('status', 'unknown')->count(),
            'active_customers' => $customers,
            'located_customers' => $mappedCustomers,
            'unlocated_customers' => $unmappedCustomers,
            'customer_coverage_percent' => $customers > 0 ? min(100, (int) round(($mappedCustomers / $customers) * 100)) : 0,
            'located_routers' => count($routerNodes),
            'unlocated_routers' => $unmappedRouters,
            'located_devices' => count($deviceLocations),
            'unlocated_devices' => $unmappedDevices,
            'published_custom_nodes' => $publishedCustomNodes->count(),
            'unlocated_custom_nodes' => max(0, $publishedCustomNodes->count() - count($topologyLocations)),
            'published_connections' => $publishedConnections->count(),
            'mapped_connections' => count($mapEdges),
            'mapped_onus' => count($onuNodes),
            'olt_onu_links' => count($oltOnuEdges),
            'unmapped_connections' => max(0, $publishedConnections->count() - count($mapEdges)),
            'generated_at' => now()->format('d M Y, h:i:s A'),
        ];
        $mapKindCounts = $nodes->groupBy('kind')->map(fn ($items) => $items->count())->toArray();

        // Read-only snapshot of the dedicated field-verified fiber register.
        // These tables remain the source of truth; this page never creates or guesses mappings.
        $fiberStats = [
            'cables' => 0,
            'verified_cables' => 0,
            'splitters' => 0,
            'closures' => 0,
            'splices' => 0,
            'verified_splices' => 0,
            'customer_mappings' => 0,
            'verified_customer_mappings' => 0,
            'pending_records' => 0,
        ];

        $fiberTables = [
            'fiber_cables', 'fiber_olt_pon_mappings', 'fiber_splice_records',
            'fiber_splitters', 'fiber_splitter_ports', 'fiber_customer_locations',
            'fiber_customer_mappings',
        ];
        foreach ($fiberTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $fiberStats['pending_records'] += DB::table($table)
                ->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', '!=', 'verified');
                })->count();
        }

        if (Schema::hasTable('fiber_cables')) {
            $fiberStats['cables'] = DB::table('fiber_cables')->count();
            $fiberStats['verified_cables'] = DB::table('fiber_cables')->where('status', 'verified')->count();
        }
        if (Schema::hasTable('fiber_splitters')) {
            $fiberStats['splitters'] = DB::table('fiber_splitters')->count();
        }
        if (Schema::hasTable('fiber_splice_closures')) {
            $fiberStats['closures'] = DB::table('fiber_splice_closures')->count();
        }
        if (Schema::hasTable('fiber_splice_records')) {
            $fiberStats['splices'] = DB::table('fiber_splice_records')->count();
            $fiberStats['verified_splices'] = DB::table('fiber_splice_records')->where('status', 'verified')->count();
        }
        if (Schema::hasTable('fiber_customer_mappings')) {
            $fiberStats['customer_mappings'] = DB::table('fiber_customer_mappings')->count();
            $fiberStats['verified_customer_mappings'] = DB::table('fiber_customer_mappings')->where('status', 'verified')->count();
        }

        $configuredLatitude = MainSiteData::getValue('ipilot_gis_default_latitude', '');
        $configuredLongitude = MainSiteData::getValue('ipilot_gis_default_longitude', '');
        $hasConfiguredCenter = is_numeric($configuredLatitude) && is_numeric($configuredLongitude)
            && (float) $configuredLatitude >= -90 && (float) $configuredLatitude <= 90
            && (float) $configuredLongitude >= -180 && (float) $configuredLongitude <= 180;
        $configuredZoom = (int) MainSiteData::getValue('ipilot_gis_default_zoom', 13);
        $mapConfig = [
            'center' => $hasConfiguredCenter
                ? [(float) $configuredLongitude, (float) $configuredLatitude]
                : [90.4125, 23.8103],
            'zoom' => min(20, max(1, $configuredZoom ?: 10)),
            'basemap' => in_array(MainSiteData::getValue('ipilot_gis_default_basemap', 'auto'), ['auto', 'street', 'satellite', 'dark'], true)
                ? MainSiteData::getValue('ipilot_gis_default_basemap', 'auto')
                : 'auto',
        ];

        return view('livewire.network-map', compact(
            'routers', 'nodes', 'customers', 'mappedCustomers', 'unmappedCustomers',
            'routerNodes', 'deviceLocations', 'mapEdges', 'mapStats', 'mapKindCounts', 'mapConfig', 'fiberStats', 'unlocatedCustomerRecords'
        ))->layout('layouts.app');
    }

    private function normalizeStatus(?string $status): string
    {
        $raw = strtolower(trim((string) $status));
        return in_array($raw, ['online','ready','connected','up','active','running'], true) ? 'online'
            : (in_array($raw, ['offline','failed','down','unreachable','disabled'], true) ? 'offline' : 'unknown');
    }
}