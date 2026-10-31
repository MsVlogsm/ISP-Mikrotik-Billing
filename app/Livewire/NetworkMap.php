<?php
namespace App\Livewire;
use App\Models\CustomersAddress;
use App\Models\CustomersInfo;
use App\Models\RouterList;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkTopologyLink;
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

        $customers = CustomersInfo::active()->count();
        $nodes = CustomersAddress::query()
            ->leftJoin('customers_infos as ci', 'ci.customer_unique_id', '=', 'customers_addresses.customer_address_unique_id')
            ->leftJoin('p_p_p_secrets as ps', 'ps.id', '=', 'ci.ppp_user_id')
            ->where('customers_addresses.label_name', 'Network Location')
            ->where('ci.status', 'active')
            ->whereNull('ci.deleted_at')
            ->whereNotNull('customers_addresses.latitude')
            ->whereNotNull('customers_addresses.longitude')
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

        $mappedCustomers = count($nodes);
        $unmappedCustomers = max(0, $customers - $mappedCustomers);
        $unmappedRouters = max(0, $routers->count() - count($routerNodes));
        $unmappedDevices = max(0, $inventoryDevices->count() - count($deviceLocations));

        $nodes = collect(array_merge($nodes, $routerNodes, $deviceLocations));
        $coordinates = $nodes->keyBy(fn ($node) => ($node['kind'] === 'router' ? 'router:' : 'device:') . $node['id']);
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
            'published_connections' => $publishedConnections->count(),
            'mapped_connections' => count($mapEdges),
            'unmapped_connections' => max(0, $publishedConnections->count() - count($mapEdges)),
            'generated_at' => now()->format('d M Y, h:i:s A'),
        ];
        $mapKindCounts = $nodes->groupBy('kind')->map(fn ($items) => $items->count())->toArray();

        return view('livewire.network-map', compact(
            'routers', 'nodes', 'customers', 'mappedCustomers', 'unmappedCustomers',
            'routerNodes', 'deviceLocations', 'mapEdges', 'mapStats', 'mapKindCounts'
        ))->layout('layouts.app');
    }

    private function normalizeStatus(?string $status): string
    {
        $raw = strtolower(trim((string) $status));
        return in_array($raw, ['online','ready','connected','up','active','running'], true) ? 'online'
            : (in_array($raw, ['offline','failed','down','unreachable','disabled'], true) ? 'offline' : 'unknown');
    }
}