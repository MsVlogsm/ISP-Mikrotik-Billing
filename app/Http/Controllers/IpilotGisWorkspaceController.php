<?php

namespace App\Http\Controllers;

use App\Models\AutomationSyncStatus;
use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkTopologyMap;
use Illuminate\Http\Request;

class IpilotGisWorkspaceController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager']), 403);
    }

    public function outsidePlant()
    {
        $this->authorizeAdmin();

        $devices = NetworkInventoryDevice::query()
            ->whereIn('type', ['olt', 'onu', 'switch', 'wifi-router', 'access-point', 'router'])
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(40);

        $stats = [
            'devices' => NetworkInventoryDevice::whereIn('type', ['olt', 'onu', 'switch', 'wifi-router', 'access-point', 'router'])->count(),
            'located' => NetworkInventoryDevice::whereIn('type', ['olt', 'onu', 'switch', 'wifi-router', 'access-point', 'router'])
                ->whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'olts' => NetworkInventoryDevice::where('type', 'olt')->count(),
            'onus' => NetworkInventoryDevice::where('type', 'onu')->count(),
        ];

        return view('xlink.ipilot-outside-plant', compact('devices', 'stats'));
    }

    public function operations()
    {
        $this->authorizeAdmin();

        $events = NetworkEvent::with('device')->orderByDesc('last_seen_at')->limit(50)->get();
        $syncStatuses = AutomationSyncStatus::query()->orderBy('label')->get();
        $stats = [
            'open_events' => NetworkEvent::where('status', 'open')->count(),
            'acknowledged_events' => NetworkEvent::where('status', 'acknowledged')->count(),
            'resolved_events' => NetworkEvent::where('status', 'resolved')->count(),
            'critical_events' => NetworkEvent::where('status', '!=', 'resolved')->whereIn('severity', ['critical', 'disaster'])->count(),
            'sync_healthy' => $syncStatuses->where('status', 'success')->count(),
            'sync_failed' => $syncStatuses->where('status', 'failed')->count(),
        ];

        return view('xlink.ipilot-network-operations', compact('events', 'syncStatuses', 'stats'));
    }

    public function planning(Request $request)
    {
        $this->authorizeAdmin();

        $maps = NetworkTopologyMap::query()->withCount(['nodes', 'links'])
            ->orderByDesc('is_default')->orderBy('name')->get();
        $mapId = (int) $request->query('map', 0);
        $map = $maps->firstWhere('id', $mapId) ?: $maps->firstWhere('is_default', true) ?: $maps->first();

        $nodes = $map ? $map->nodes()->orderBy('type')->orderBy('name')->get() : collect();
        $links = $map ? $map->links()->orderBy('connection_type')->get() : collect();
        $stats = [
            'maps' => $maps->count(),
            'nodes' => $nodes->count(),
            'located_nodes' => $nodes->filter(fn ($node) => $node->latitude !== null && $node->longitude !== null)->count(),
            'published_nodes' => $nodes->where('is_published', true)->count(),
            'links' => $links->count(),
            'published_links' => $links->where('is_published', true)->count(),
        ];

        return view('xlink.ipilot-planning', compact('maps', 'map', 'nodes', 'links', 'stats'));
    }
}
