<x-app-layout>
    <x-slot name="header">ONU MAC Ledger</x-slot>
    <div class="container-fluid px-1">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
                <div class="small text-uppercase fw-semibold text-muted">FTTH Network · Identity History</div>
                <h3 class="mb-1">ONU MAC Ledger</h3>
                <div class="text-muted small">Stable ONU identity and MAC-change history. The ledger never changes Customer/PPPoE mappings by itself.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('network-inventory.onu-management') }}">ONU Management</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('network-inventory.olt') }}">OLT Devices</a>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('network-inventory.onu-mac-ledger', request()->query()) }}"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Ledger identities</div><div class="fs-2 fw-bold">{{ number_format($stats['total']) }}</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Linked customers</div><div class="fs-2 fw-bold text-success">{{ number_format($stats['mapped']) }}</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Unmapped</div><div class="fs-2 fw-bold">{{ number_format($stats['unmapped']) }}</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">MAC changes</div><div class="fs-2 fw-bold {{ $stats['mac_changes'] ? 'text-warning' : '' }}">{{ number_format($stats['mac_changes']) }}</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Stale online status</div><div class="fs-2 fw-bold {{ $stats['stale'] ? 'text-danger' : 'text-success' }}">{{ number_format($stats['stale']) }}</div></div></div></div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body border-bottom">
                <form method="GET" action="{{ route('network-inventory.onu-mac-ledger') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-5"><label class="form-label small fw-semibold">Search identity</label><input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="Current/previous MAC, serial, customer ID, PPPoE, ONU ID…"></div>
                    <div class="col-6 col-lg-3"><label class="form-label small fw-semibold">OLT</label><select class="form-select" name="olt"><option value="">All OLTs</option>@foreach($olts as $olt)<option value="{{ $olt->id }}" @selected((string)$oltId === (string)$olt->id)>{{ $olt->name }}{{ $olt->location ? ' · '.$olt->location : '' }}</option>@endforeach</select></div>
                    <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Status</label><select class="form-select" name="status"><option value="">All statuses</option><option value="online" @selected($status === 'online')>Online</option><option value="offline" @selected($status === 'offline')>Offline</option><option value="unknown" @selected($status === 'unknown')>Unknown</option></select></div>
                    <div class="col-6 col-lg-1"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="changed" value="1" id="changedOnly" @checked($changed)><label class="form-check-label small" for="changedOnly">Changed</label></div></div>
                    <div class="col-6 col-lg-1 d-flex gap-1"><button class="btn btn-primary flex-fill" aria-label="Search"><i class="bi bi-search"></i></button><a class="btn btn-outline-secondary" href="{{ route('network-inventory.onu-mac-ledger') }}" aria-label="Reset"><i class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>OLT / PON</th><th>ONU / Serial</th><th>Current MAC</th><th>Previous MAC / Changed</th><th>Customer / PPPoE</th><th>Status</th><th>Last Seen</th><th>Notes</th><th></th></tr></thead>
                    <tbody>
                    @forelse($mappings as $m)
                        <tr>
                            <td><div class="fw-semibold">{{ $m->olt?->name ?: 'OLT unavailable' }}</div><div class="small text-muted">PON {{ $m->pon_port ?: '—' }}</div></td>
                            <td><div class="fw-semibold">{{ $m->onu_id ?: '—' }}</div><div class="small text-muted">{{ $m->serial_no ?: 'No serial' }}</div></td>
                            <td class="font-monospace">{{ $m->current_mac ?: '—' }}</td>
                            <td><div class="font-monospace">{{ $m->previous_mac ?: '—' }}</div><div class="small text-muted">{{ $m->mac_changed_at?->format('d M Y H:i:s') ?: 'No recorded change' }}</div></td>
                            <td><div class="fw-semibold">{{ $m->customer?->customer_name ?: 'Unmapped / historical CID' }}</div><div class="small text-muted">{{ $m->customer_unique_id ?: 'No customer ID' }}</div><div class="small text-muted">PPPoE: {{ $m->pppoe_username ?: '—' }}</div></td>
                            <td><span class="badge text-bg-{{ $m->status === 'online' ? 'success' : ($m->status === 'offline' ? 'danger' : 'secondary') }}">{{ ucfirst($m->status ?: 'unknown') }}</span></td>
                            <td class="small">{{ $m->last_seen_at?->format('d M Y H:i:s') ?: '—' }}</td>
                            <td class="small text-muted" style="min-width:180px;max-width:280px;white-space:normal;overflow-wrap:anywhere">{{ $m->notes ?: '—' }}</td>
                            <td>@if($m->olt)<a class="btn btn-sm btn-outline-primary" href="{{ route('network-inventory.olt.customers', $m->olt) }}" title="Review ONU mapping"><i class="bi bi-link-45deg"></i></a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center py-5"><div class="text-muted mb-2"><i class="bi bi-journal-text fs-3 d-block mb-2"></i><strong>No ONU MAC ledger records yet</strong></div><div class="small text-muted">Records are seeded from saved ONU identities during the next successful inventory sync. Customer/PPPoE links are only reused when exact evidence agrees.</div><a class="btn btn-sm btn-outline-primary mt-3" href="{{ route('network-inventory.olt') }}">Review OLT inventory</a></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap justify-content-between gap-2 p-3 border-top small text-muted"><span>{{ number_format($mappings->total()) }} ledger rows</span><span>Page {{ $mappings->currentPage() }} of {{ max(1, $mappings->lastPage()) }}</span></div>
            @if($mappings->hasPages())<div class="p-3">{{ $mappings->links() }}</div>@endif
        </div>
    </div>
</x-app-layout>
