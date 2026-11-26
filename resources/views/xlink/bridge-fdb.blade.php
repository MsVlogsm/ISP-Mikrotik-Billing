<x-app-layout>
@include('xlink.partials.olt-management-nav', ['active' => 'bridge-fdb', 'device' => $device])

<div class="container-fluid px-3 px-xl-4 py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <div class="text-muted small mb-1">
                <a href="{{ route('network-inventory') }}">Network Inventory</a>
                <span class="mx-1">/</span>
                @if($device->type === 'olt')
                    <a href="{{ route('network-inventory.olt') }}">OLT Management</a>
                @else
                    <span>Switch Inventory</span>
                @endif
                <span class="mx-1">/</span>{{ $device->name }}
            </div>
            <h1 class="h3 mb-1"><i class="bi bi-diagram-3 me-2"></i>L2 Bridge / FDB</h1>
            <div class="text-muted">{{ $device->name }} · {{ $device->vendor ?: ucfirst($device->type) }} {{ $device->model ?: '' }} · {{ $device->ip_address ?: $device->host ?: 'No management address' }}</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($device->type === 'olt')
                <a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt.dashboard', $device) }}"><i class="bi bi-speedometer2 me-1"></i> OLT Overview</a>
                <a class="btn btn-outline-secondary" href="{{ route('network-inventory.universal-search') }}"><i class="bi bi-search me-1"></i> Universal Search</a>
            @endif
            @if($pollEnabled)
                <form method="POST" action="{{ route('network-inventory.device.bridge-fdb.poll', $device) }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.innerHTML='<span class=&quot;spinner-border spinner-border-sm me-1&quot;></span>Reading FDB…';">
                    @csrf
                    <label class="form-check small text-muted me-2"><input class="form-check-input" type="checkbox" name="confirm_read_only_poll" value="1" required> Confirm read-only SNMP poll</label>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i> Poll Bridge FDB</button>
                </form>
            @else
                <button class="btn btn-primary" type="button" disabled title="Enable only after staging SNMP verification"><i class="bi bi-lock me-1"></i> Live Poll Disabled</button>
            @endif
        </div>
    </div>

    @if(session('bridge_fdb_message'))
        <div class="alert alert-{{ session('bridge_fdb_success') ? 'success' : 'warning' }} alert-dismissible fade show" role="alert">
            <strong>{{ session('bridge_fdb_success') ? 'FDB poll result' : 'FDB status' }}</strong>
            <div class="mt-1">{{ session('bridge_fdb_message') }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @unless($pollEnabled)
        <div class="alert alert-info d-flex gap-2 align-items-start">
            <i class="bi bi-shield-lock fs-5"></i>
            <div>
                <strong>Read-only polling is currently disabled.</strong>
                <div class="small mt-1">This page displays the latest saved FDB snapshot only. Configure a dedicated read-only SNMP community and verify the collector on Staging before enabling <code>OLT_BRIDGE_FDB_POLL_ENABLED=true</code>. No SNMP SET or bridge configuration command is provided by this module.</div>
            </div>
        </div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">FDB entries</div><div class="fs-3 fw-semibold">{{ number_format($stats['total']) }}</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">Learned MACs</div><div class="fs-3 fw-semibold text-success">{{ number_format($stats['learned']) }}</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">Other / Invalid</div><div class="fs-3 fw-semibold">{{ number_format($stats['other'] + $stats['invalid']) }}</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">Self / Mgmt</div><div class="fs-3 fw-semibold">{{ number_format($stats['self'] + $stats['management']) }}</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">VLANs</div><div class="fs-3 fw-semibold">{{ number_format($stats['vlans']) }}</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">Mapped interfaces</div><div class="fs-3 fw-semibold">{{ number_format($stats['interfaces']) }}</div></div></div></div>
    </div>

    @if($pollFailure)
        <div class="alert alert-danger" role="alert">
            <strong>POLL FAILED</strong>
            <div class="mt-1">{{ $pollFailure->error_message ?: 'The latest Bridge FDB polling attempt failed.' }}</div>
            <div class="small mt-1">
                Last successful poll:
                @if($snapshot)
                    {{ $snapshot->sampled_at->format('d M Y H:i:s') }}
                @else
                    No successful poll has been recorded.
                @endif
            </div>
            @if($snapshot)
                <div class="small mt-1">Showing the last successful FDB data; current MAC visibility may have changed since that poll.</div>
            @else
                <div class="small mt-1">No saved successful FDB data is available to display.</div>
            @endif
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-semibold">Latest successful Bridge FDB snapshot</div>
                @if($snapshot)
                    <div class="small text-muted">
                        {{ $snapshot->sampled_at->format('d M Y H:i:s') }}
                        · Source: {{ strtoupper($snapshot->source ?: 'unknown') }}
                        · Poll {{ number_format((int) $snapshot->poll_duration_ms) }} ms
                        · Status: <span class="badge {{ $snapshot->status === 'ok' ? 'bg-success' : ($snapshot->status === 'empty' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ strtoupper($snapshot->status) }}</span>
                    </div>
                    @if(!empty($snapshot->meta['truncated']))
                        <div class="small text-warning mt-1">Entry display limit reached ({{ number_format((int) ($snapshot->meta['entry_limit'] ?? 0)) }}). Increase the configured cap only after checking switch FDB size.</div>
                    @endif
                    @if($snapshot->error_message)<div class="small text-danger mt-1">{{ $snapshot->error_message }}</div>@endif
                @else
                    <div class="small text-muted">No Bridge FDB snapshot has been saved yet.</div>
                @endif
            </div>
            <div class="small text-muted">
                @if($snapshot && $snapshot->source === 'dot1q')
                    VLAN-aware Q-BRIDGE-MIB
                @elseif($snapshot && $snapshot->source === 'dot1d')
                    Standard BRIDGE-MIB (no VLAN index)
                @else
                    Awaiting first successful read
                @endif
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold"><i class="bi bi-arrow-left-right me-1"></i> MAC movement / flapping history</div>
            <span class="small text-muted">{{ $movements->count() }} recent movement records</span>
        </div>
        @if($movements->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>MAC</th><th>VLAN</th><th>From</th><th>To</th><th>Last moved</th><th>Moves / 10 min</th><th>State</th></tr></thead>
                    <tbody>
                    @foreach($movements as $movement)
                        <tr>
                            <td><code>{{ $movement->mac_address }}</code></td>
                            <td>{{ $movement->vlan_id ?? '—' }}</td>
                            <td>{{ $movement->from_interface ?: ('ifIndex '.($movement->from_if_index ?? '—')) }}</td>
                            <td>{{ $movement->to_interface ?: ('ifIndex '.($movement->to_if_index ?? '—')) }}</td>
                            <td>{{ $movement->moved_at?->format('d M H:i:s') }}</td>
                            <td>{{ $movement->moves_in_10m }}</td>
                            <td>@if($movement->is_flapping)<span class="badge bg-warning text-dark">Flapping</span>@else<span class="badge bg-secondary">Moved</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="card-body text-muted small">No confirmed interface movement is recorded yet. Movement is detected only when the same learned MAC/VLAN is observed on a different resolved interface in consecutive successful snapshots.</div>
        @endif
    </div>

    <div class="card">
        <div class="card-header bg-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">Forwarding Database</div>
            <span class="text-muted small">{{ number_format($entries->total()) }} matching rows</span>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('network-inventory.device.bridge-fdb', $device) }}" class="row g-2 align-items-end">
                <div class="col-12 col-lg-4">
                    <label for="bridge-fdb-q" class="form-label small">MAC / interface / ifIndex</label>
                    <input id="bridge-fdb-q" class="form-control" name="q" value="{{ $search }}" placeholder="00:11:22:33:44:55 or GE0/1">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="bridge-fdb-vlan" class="form-label small">VLAN ID</label>
                    <input id="bridge-fdb-vlan" class="form-control" name="vlan" inputmode="numeric" pattern="[0-9]*" value="{{ $vlan }}" placeholder="Any VLAN">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="bridge-fdb-port" class="form-label small">Bridge port</label>
                    <input id="bridge-fdb-port" class="form-control" name="port" value="{{ $port }}" placeholder="Port / interface">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="bridge-fdb-status" class="form-label small">Entry status</label>
                    <select id="bridge-fdb-status" class="form-select" name="status">
                        <option value="">All statuses</option>
                        <option value="learned" @selected($status === 'learned')>Learned</option>
                        <option value="self" @selected($status === 'self')>Self</option>
                        <option value="management" @selected($status === 'management')>Management</option>
                        <option value="other" @selected($status === 'other')>Other</option>
                        <option value="invalid" @selected($status === 'invalid')>Invalid</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('network-inventory.device.bridge-fdb', $device) }}" title="Reset filters"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>MAC Address</th>
                        <th>VLAN / FDB ID</th>
                        <th>Bridge Port</th>
                        <th>Interface / ifIndex</th>
                        <th>FDB Status</th>
                        <th>Subscriber / ONU identity</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td><code class="fw-semibold">{{ $entry->mac_address }}</code></td>
                            <td>{{ $entry->vlan_id !== null ? $entry->vlan_id : '—' }}<small class="d-block text-muted">FDB {{ $entry->fdb_id ?? '—' }}</small></td>
                            <td>{{ $entry->bridge_port ?? '—' }}</td>
                            <td>{{ $entry->interface_name ?: 'Unresolved' }}<small class="d-block text-muted">ifIndex {{ $entry->if_index ?? '—' }}</small></td>
                            <td><span class="badge {{ $entry->entry_status === 'learned' ? 'bg-success' : ($entry->entry_status === 'self' ? 'bg-primary' : 'bg-secondary') }}">{{ ucfirst($entry->entry_status) }}</span></td>
                            <td>
                                @if($entry->onu_identity)<small class="d-block text-muted">{{ $entry->onu_identity }}</small>@endif
                                @if($entry->subscriber_name)
                                    <strong>{{ $entry->subscriber_name }}</strong>
                                    <small class="d-block text-muted">{{ $entry->subscriber_id ?: '' }} {{ $entry->pppoe_username ? '· '.$entry->pppoe_username : '' }}</small>
                                    <small class="d-block text-success">{{ $entry->identity_source }}</small>
                                @elseif($entry->identity_source)
                                    <span class="text-warning small">{{ $entry->identity_source }}</span>
                                @else
                                    <span class="text-muted">No exact subscriber identity</span>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ strtoupper($entry->source) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-5">
                            @if($snapshot)
                                No FDB rows match these filters. Clear the filters or run another read-only poll.
                            @else
                                No saved FDB table yet. Configure Staging SNMP read-only access and perform the first poll.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($entries->hasPages())
            <div class="card-footer bg-body">{{ $entries->links() }}</div>
        @endif
    </div>

    <div class="small text-muted mt-3">
        <i class="bi bi-shield-check me-1"></i>
        Read-only BRIDGE-MIB / Q-BRIDGE-MIB data. Subscriber labels are shown only for an exact, unique stored MAC/ONU identity; ambiguous and unmatched addresses are never auto-assigned.
    </div>
</div>
</x-app-layout>
