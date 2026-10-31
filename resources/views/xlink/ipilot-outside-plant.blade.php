<x-app-layout>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h2 class="mb-1">Network & Outside Plant</h2><p class="text-muted mb-0">Verified network inventory and known geographic coordinates from X-Link records.</p></div>
        <div class="d-flex gap-2"><a href="{{ route('network-map') }}" class="btn btn-outline-primary">Open Network Map</a><a href="{{ route('network-inventory') }}" class="btn btn-outline-secondary">Device Inventory</a></div>
    </div>
    <div class="row g-3 mb-4">
        @foreach([['Inventory Devices',$stats['devices']],['Devices with Coordinates',$stats['located']],['OLTs',$stats['olts']],['ONUs',$stats['onus']]] as [$label,$value])
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-semibold">{{ number_format($value) }}</div></div></div></div>
        @endforeach
    </div>
    <div class="alert alert-info">Only stored device coordinates are shown. No address geocoding, street position or cable route is fabricated. Use the existing Network Map and Topology Designer to maintain verified locations and connections.</div>
    <div class="card">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2"><strong>Outside Plant Inventory</strong><span class="small text-muted">{{ $devices->total() }} matching devices</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Device</th><th>Type / Vendor</th><th>IP / Host</th><th>Location</th><th>Coordinates</th><th>Status</th><th>Last Check</th></tr></thead>
            <tbody>
            @forelse($devices as $device)
                <tr>
                    <td><strong>{{ $device->name }}</strong><div class="small text-muted">{{ $device->model }} {{ $device->serial_no ? '· '.$device->serial_no : '' }}</div></td>
                    <td>{{ strtoupper((string)$device->type) }}<div class="small text-muted">{{ $device->vendor }}</div></td>
                    <td>{{ $device->ip_address ?: $device->host ?: '—' }}</td>
                    <td>{{ $device->location ?: 'Not recorded' }}</td>
                    <td>{{ $device->latitude !== null && $device->longitude !== null ? $device->latitude.', '.$device->longitude : 'Not mapped' }}</td>
                    <td><span class="badge {{ in_array(strtolower((string)($device->health_status ?: $device->status)),['online','up','healthy','connected'])?'bg-success':'bg-secondary' }}">{{ $device->health_status ?: $device->status ?: 'Unknown' }}</span></td>
                    <td>{{ $device->last_checked_at?->diffForHumans() ?? 'Never' }}</td>
                </tr>
            @empty<tr><td colspan="7" class="text-center text-muted py-4">No matching inventory records found.</td></tr>@endforelse
            </tbody>
        </table></div>
        <div class="card-footer">{{ $devices->links() }}</div>
    </div>
</div>
</x-app-layout>
