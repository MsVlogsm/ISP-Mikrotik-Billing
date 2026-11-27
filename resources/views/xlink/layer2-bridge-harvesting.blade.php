<x-app-layout>
<div class="container-fluid px-3 px-xl-4 py-3">
    <div class="mb-4">
        <h1 class="h3 mb-1"><i class="bi bi-diagram-3 me-2"></i>Layer-2 OLT Switch Bridge Harvesting</h1>
        <div class="text-muted">Unified read-only view of saved OLT, Switch and MikroTik bridge forwarding data</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Configured Sources</div><div class="fs-3 fw-semibold">{{ number_format($stats['sources']) }}</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Healthy Sources</div><div class="fs-3 fw-semibold text-success">{{ number_format($stats['healthy']) }}</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Matching MAC Entries</div><div class="fs-3 fw-semibold">{{ number_format($stats['entries']) }}</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Recent Flapping Alerts</div><div class="fs-3 fw-semibold {{ $stats['flapping'] ? 'text-warning' : '' }}">{{ number_format($stats['flapping']) }}</div></div></div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-body fw-semibold">Harvesting Sources</div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Type</th><th>Device</th><th>Address</th><th>Last Status</th><th>Last Successful Snapshot</th><th>MAC Entries</th><th>Details</th></tr></thead>
            <tbody>@forelse($sources as $source)
                <tr><td>{{ $source['type'] }}</td><td>{{ $source['device'] }}</td><td>{{ $source['address'] ?: '—' }}</td>
                    <td><span class="badge {{ in_array($source['status'], ['ok', 'empty'], true) ? 'bg-success' : ($source['status'] === 'error' ? 'bg-danger' : 'bg-secondary') }}">{{ strtoupper($source['status']) }}</span></td>
                    <td>{{ $source['sampled_at']?->format('d M Y H:i:s') ?? 'Never' }}</td><td>{{ number_format($source['entries']) }}</td>
                    <td class="small text-danger">{{ $source['error'] ?: '—' }}</td></tr>
            @empty<tr><td colspan="7" class="text-center text-muted py-4">No OLT, Switch or MikroTik source is configured.</td></tr>@endforelse</tbody>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-body fw-semibold">Unified MAC / VLAN / Bridge Port Table</div>
        <div class="card-body border-bottom"><form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-lg-5"><label class="form-label small" for="l2-q">MAC / Device / Port / Bridge</label><input id="l2-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search MAC, device or interface"></div>
            <div class="col-6 col-lg-2"><label class="form-label small" for="l2-vlan">VLAN ID</label><input id="l2-vlan" class="form-control" name="vlan" inputmode="numeric" value="{{ $vlan }}"></div>
            <div class="col-6 col-lg-3"><label class="form-label small" for="l2-source">Source</label><select id="l2-source" class="form-select" name="source"><option value="">All sources</option><option value="OLT/Switch" @selected($sourceFilter === 'OLT/Switch')>OLT / Switch SNMP</option><option value="MikroTik" @selected($sourceFilter === 'MikroTik')>MikroTik RouterOS API</option></select></div>
            <div class="col-12 col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Filter</button><a class="btn btn-outline-secondary" href="{{ route('layer2-bridge-harvesting') }}">Reset</a></div>
        </form></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>MAC Address</th><th>VLAN</th><th>Device / Type</th><th>Bridge</th><th>Bridge Port / Interface</th><th>Entry State</th><th>Source</th><th>Sampled</th></tr></thead>
            <tbody>@forelse($entries as $entry)
                <tr><td><code>{{ $entry['mac'] }}</code></td><td>{{ $entry['vlan'] ?? '—' }}</td><td>{{ $entry['device'] }}<div class="small text-muted">{{ $entry['type'] }}</div></td><td>{{ $entry['bridge'] ?: '—' }}</td><td>{{ $entry['port'] }}</td><td>{{ ucfirst($entry['status']) }}</td><td>{{ $entry['source'] }}</td><td class="text-nowrap">{{ $entry['sampled_at']?->format('d M H:i:s') }}</td></tr>
            @empty<tr><td colspan="8" class="text-center text-muted py-4">No saved MAC entries match these filters. GET requests display saved snapshots only; they do not poll devices.</td></tr>@endforelse</tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-header bg-body fw-semibold">Unified MAC Movement / Flapping History</div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>MAC</th><th>VLAN</th><th>Device</th><th>From</th><th>To</th><th>Moved At</th><th>Moves / 10m</th><th>State</th><th>Source</th></tr></thead>
            <tbody>@forelse($movements as $movement)
                <tr><td><code>{{ $movement['mac'] }}</code></td><td>{{ $movement['vlan'] ?? '—' }}</td><td>{{ $movement['device'] }}</td><td>{{ $movement['from'] ?: '—' }}</td><td>{{ $movement['to'] ?: '—' }}</td><td>{{ $movement['moved_at']?->format('d M H:i:s') }}</td><td>{{ $movement['moves'] }}</td><td>@if($movement['flapping'])<span class="badge bg-warning text-dark">Flapping</span>@else<span class="badge bg-secondary">Moved</span>@endif</td><td>{{ $movement['source'] }}</td></tr>
            @empty<tr><td colspan="9" class="text-center text-muted py-4">No saved MAC movement records.</td></tr>@endforelse</tbody>
        </table></div>
    </div>
</div>
</x-app-layout>
