<x-app-layout>
<div class="container-fluid px-1">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>MikroTik L2 Bridge Hosts</h3>
            <div class="text-muted">Read-only RouterOS Bridge Host table and MAC movement history</div>
        </div>
        @if($selectedRouter)
            <form method="GET" action="{{ route('mikrotik-bridge-hosts') }}" class="d-flex align-items-center gap-2">
                <label for="router_id" class="form-label mb-0">Router</label>
                <select id="router_id" name="router_id" class="form-select" onchange="this.form.submit()">
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}" @selected($selectedRouter->id === $router->id)>
                            {{ $router->router_name }}
                        </option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if(!$selectedRouter)
        <div class="alert alert-info">No MikroTik router is registered yet.</div>
    @else
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <small class="text-muted">Latest successful snapshot</small>
                    <div class="fs-5 fw-semibold mt-2">{{ $latestSuccessfulSnapshot?->sampled_at?->format('d M Y H:i:s') ?? 'Not available' }}</div>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <small class="text-muted">Stored MAC entries</small>
                    <div class="fs-3 fw-bold mt-2">{{ $latestSuccessfulSnapshot?->entry_count ?? 0 }}</div>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <small class="text-muted">Dynamic hosts</small>
                    <div class="fs-3 fw-bold mt-2">{{ $latestSuccessfulSnapshot?->dynamic_count ?? 0 }}</div>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <small class="text-muted">Latest polling status</small>
                    <div class="mt-2">
                        @php($status = $latestAttempt?->status ?? 'not polled')
                        <span class="badge {{ $status === 'ok' ? 'bg-success' : ($status === 'empty' ? 'bg-secondary' : ($status === 'error' ? 'bg-danger' : 'bg-warning text-dark')) }}">
                            {{ strtoupper($status) }}
                        </span>
                        @if($latestAttempt?->sampled_at)
                            <small class="d-block text-muted mt-1">{{ $latestAttempt->sampled_at->format('d M Y H:i:s') }}</small>
                        @endif
                    </div>
                </div></div>
            </div>
        </div>

        @if($latestAttempt?->status === 'error')
            <div class="alert alert-warning">
                Latest polling attempt failed: {{ $latestAttempt->error_message ?: 'No error detail was recorded.' }}
                @if($latestSuccessfulSnapshot)
                    <div class="small mt-1">Showing entries from the last successful snapshot.</div>
                @endif
            </div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">Latest MAC / Bridge Port Table</h5>
                @if($latestSuccessfulSnapshot)
                    <small class="text-muted">Snapshot: {{ $latestSuccessfulSnapshot->sampled_at->format('d M Y H:i:s') }}</small>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>MAC Address</th>
                            <th>VLAN</th>
                            <th>Bridge</th>
                            <th>Bridge Port</th>
                            <th>Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries ?? [] as $entry)
                            <tr>
                                <td class="font-monospace">{{ strtoupper($entry->mac_address) }}</td>
                                <td>{{ $entry->vlan_id ?? '—' }}</td>
                                <td>{{ $entry->bridge_name ?: '—' }}</td>
                                <td>{{ $entry->interface_name ?: '—' }}</td>
                                <td class="d-flex flex-wrap gap-1">
                                    @if($entry->is_dynamic)<span class="badge bg-primary-subtle text-primary">Dynamic</span>@endif
                                    @if($entry->is_local)<span class="badge bg-secondary-subtle text-secondary">Local</span>@endif
                                    @if($entry->is_external)<span class="badge bg-info-subtle text-info">External</span>@endif
                                    @if($entry->is_disabled)<span class="badge bg-warning-subtle text-warning">Disabled</span>@endif
                                    @if($entry->is_invalid)<span class="badge bg-danger-subtle text-danger">Invalid</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No MAC entries in the latest successful snapshot.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($entries)
                <div class="card-footer">{{ $entries->links() }}</div>
            @endif
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">MAC Movement History</h5>
                <small class="text-muted">Dynamic, non-local hosts only</small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Moved At</th>
                            <th>MAC Address</th>
                            <th>VLAN</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movements ?? [] as $movement)
                            <tr>
                                <td>{{ $movement->moved_at->format('d M Y H:i:s') }}</td>
                                <td class="font-monospace">{{ strtoupper($movement->mac_address) }}</td>
                                <td>{{ $movement->vlan_id ?? '—' }}</td>
                                <td>{{ $movement->from_bridge ?: '—' }} / {{ $movement->from_interface ?: '—' }}</td>
                                <td>{{ $movement->to_bridge ?: '—' }} / {{ $movement->to_interface ?: '—' }}</td>
                                <td>
                                    @if($movement->is_flapping)
                                        <span class="badge bg-danger">Flapping · {{ $movement->moves_in_10m }} moves / 10m</span>
                                    @else
                                        <span class="badge bg-secondary">{{ $movement->moves_in_10m }} move(s) / 10m</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No MAC movements recorded for this router.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($movements)
                <div class="card-footer">{{ $movements->links() }}</div>
            @endif
        </div>
    @endif
</div>
</x-app-layout>
