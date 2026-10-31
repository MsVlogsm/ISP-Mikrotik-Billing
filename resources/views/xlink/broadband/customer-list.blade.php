<x-app-layout>
@push('styles')
<link rel="stylesheet" href="{{ asset('xlink-broadband/customers.css') }}">
<link rel="stylesheet" href="{{ asset('xlink-broadband/ui-polish-20260826.css') }}">
<style>
.broadband-customer-table th{white-space:nowrap;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}
.broadband-customer-table td{vertical-align:middle}
.customer-actions{min-width:145px}.customer-actions form{display:inline}
.customer-actions .btn{margin:.12rem}.customer-diagnostics{display:inline-flex;align-items:center;gap:.15rem;flex-wrap:wrap}.customer-diagnostic-result{font-size:.75rem;min-height:1.1rem}.traffic-metric{border:1px solid var(--bs-border-color);border-radius:.6rem;padding:.8rem 1rem}.traffic-metric small{display:block;color:var(--bs-secondary-color)}.traffic-metric strong{font-size:1.25rem}.traffic-live-dot{display:inline-block;width:.5rem;height:.5rem;border-radius:50%;background:currentColor;box-shadow:0 0 0 .25rem color-mix(in srgb,currentColor 12%,transparent)}.customer-live-pulse{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;background:#16a34a;animation:customerPulse 1.5s infinite;vertical-align:middle;margin-right:.35rem}@keyframes customerPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}70%{box-shadow:0 0 0 .4rem rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}@media(prefers-reduced-motion:reduce){.customer-live-pulse{animation:none}}
.customer-meta{font-size:.78rem}.filter-card .form-label{font-size:.78rem;font-weight:600;color:#6c757d}
</style>
@endpush

@php
    $title = match($mode) {
        'due' => 'Due Customers',
        'paid' => 'Paid Customers',
        'inactive' => 'Left Customers',
        'unverified' => 'Unverified Customers',
        'online' => 'Online Customers',
        'disabled' => 'Disabled Customers',
        default => 'Customer List',
    };
@endphp

<div class="container-fluid py-2">
    @if(session('broadband_message'))
        <div class="alert alert-success py-2">{{ session('broadband_message') }}</div>
    @endif
    @if($errors->has('customer'))
        <div class="alert alert-danger py-2">{{ $errors->first('customer') }}</div>
    @endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <span class="text-uppercase small text-muted fw-semibold">Broadband</span>
            <h3 class="mb-0">{{ $title }}</h3>
            <small class="text-muted">Search and manage broadband customers without changing router configuration unless an action is explicitly requested.</small>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="{{ route('customer-add') }}"><i class="bi bi-person-plus me-1"></i>Add Customer</a>
            <a class="btn btn-primary" href="{{ route('broadband-packages') }}"><i class="bi bi-speedometer2 me-1"></i>Packages</a>
        </div>
    </div>

    @if($mode === 'inactive' && is_array($leftStats))
        <div class="row g-2 mb-3" aria-label="Left customer summary">
            <div class="col-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
                    <div class="small text-muted">Total Left Customers</div><div class="fs-4 fw-bold">{{ number_format($leftStats['total'] ?? 0) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
                    <div class="small text-muted">Pending / Partial Recovery</div><div class="fs-4 fw-bold text-warning">{{ number_format($leftStats['pending_recovery'] ?? 0) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
                    <div class="small text-muted">Recovered</div><div class="fs-4 fw-bold text-success">{{ number_format($leftStats['recovered'] ?? 0) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
                    <div class="small text-muted">Recovery Issues</div><div class="fs-4 fw-bold text-danger">{{ number_format($leftStats['issues'] ?? 0) }}</div>
                </div></div>
            </div>
        </div>
    @endif

    <div class="card shadow-sm border-0 mb-3 filter-card">
        <div class="card-body">
            <form method="GET" class="row g-2">
                @if($mode !== 'all')
                    <input type="hidden" name="status" value="{{ $mode }}">
                @endif
                <div class="col-12 col-lg-4">
                    <label class="form-label mb-1">Search customer</label>
                    <input class="form-control" name="q" value="{{ request('q') }}" placeholder="CID, name, mobile, username, IP, NID, address, router or package">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1">Router</label>
                    <select class="form-select" name="router_id">
                        <option value="">All Routers</option>
                        @foreach($routers as $r)
                            <option value="{{ $r->router_name }}" @selected(request('router_id') === $r->router_name)>{{ $r->router_name }}</option>
                        @endforeach
                    </select>
                </div>
                @if($mode === 'inactive')
                    <div class="col-6 col-lg-2">
                        <label class="form-label mb-1">Recovery status</label>
                        <select class="form-select" name="recovery_status">
                            <option value="">All recovery statuses</option>
                            @foreach(['pending'=>'Pending','partial'=>'Partially recovered','recovered'=>'Recovered','not_required'=>'Not required','issue'=>'Recovery issue'] as $value=>$label)
                                <option value="{{ $value }}" @selected(request('recovery_status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label mb-1">Zone / Area</label>
                        <input class="form-control" name="zone_name" value="{{ request('zone_name') }}" placeholder="Zone">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label mb-1">Left from</label>
                        <input class="form-control" type="date" name="left_from" value="{{ request('left_from') }}">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label mb-1">Left to</label>
                        <input class="form-control" type="date" name="left_to" value="{{ request('left_to') }}">
                    </div>
                @endif
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1">Rows</label>
                    <select class="form-select" name="rows">
                        @foreach([50,100,250,500] as $size)
                            <option value="{{ $size }}" @selected((int)request('rows',50) === $size)>{{ $size }} rows</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-lg-3 d-flex align-items-end gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-search me-1"></i>Search</button>
                    <a class="btn btn-outline-secondary" href="{{ url()->current() }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <strong>{{ $mode === 'inactive' ? 'Left Customer Register' : ($mode === 'disabled' ? 'Disabled Customer List' : 'Customer List') }}</strong>
            <span class="text-muted small">{{ number_format($customers->total()) }} records</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 broadband-customer-table">
                <thead>
                    <tr>
                        @if($mode === 'inactive')
                            <th>SL</th><th>C-Code</th><th>Customer</th><th>Mobile</th><th>Zone</th><th>Address</th>
                            <th>Package</th><th>Server</th><th>Left Date</th><th>Products</th><th>Recovery</th><th>Status</th><th>Left Reason</th><th>Actions</th>
                        @else
                            <th>CID</th><th>Customer</th><th>Connection</th><th>Mobile</th><th>Package</th>
                            <th>Router</th><th>Billing</th><th>Address / Area</th><th>Status</th><th>Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @forelse($customers as $c)
                    @php
                        $customerId = encrypt($c->customer_unique_id);
                        $address = $c->customerAddress->map(fn($a) => implode(', ', array_filter([$a->input_type_text, $a->input_type_dropdown, $a->input_type_textarea])))->filter()->implode(', ');
                        $connection = $c->pppUser->username ?? $c->pppUser->ppp_remote_ip ?? '—';
                        $status = $c->status ?: 'unknown';
                        $statusClass = in_array($status, ['active','online']) ? 'success' : (in_array($status, ['inactive','disable']) ? 'secondary' : 'warning');
                    @endphp
                    <tr>
                        @if($mode === 'inactive')
                            <td>{{ $customers->firstItem() + $loop->index }}</td>
                            <td><a class="fw-semibold text-decoration-none" href="{{ route('customers.show', $customerId) }}">{{ $c->customer_unique_id }}</a></td>
                            <td><strong>{{ $c->customer_name ?: 'Unnamed' }}</strong><div class="customer-meta text-muted">{{ $c->email ?: 'No email' }}</div></td>
                            <td>
                                @if($c->mobile)
                                    @php($dialNumber = preg_replace('/[^0-9+]/', '', (string) $c->mobile))
                                    <span class="d-inline-flex align-items-center gap-2 text-nowrap">
                                        <span>{{ $c->mobile }}</span>
                                        <a class="btn btn-sm btn-outline-success py-1 px-2" href="tel:{{ $dialNumber }}" aria-label="Call {{ $c->mobile }}" title="Call customer"><i class="bi bi-telephone-fill" aria-hidden="true"></i> Call</a>
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $c->zone_name ?: '—' }}</td>
                            <td>{{ $address ?: ($c->address ?: '—') }}</td>
                            <td>{{ $c->package->package ?? $c->pppUser->package_name ?? $c->package_name ?? '—' }}</td>
                            <td>{{ $c->server_label ?: ($c->pppUser->router_name ?? '—') }}</td>
                            <td>{{ $c->left_date?->format('d M Y') ?: '—' }}</td>
                            <td>{{ $c->left_equipment ?: 'None' }}</td>
                            <td>{{ $c->recovery_status ? ucfirst($c->recovery_status) : '—' }}</td>
                            <td><span class="badge text-bg-{{ $statusClass }}">{{ ucfirst($status) }}</span></td>
                            <td>{{ $c->left_reason ?: '—' }}</td>
                        @else
                            <td><a class="fw-semibold text-decoration-none" href="{{ route('customers.show', $customerId) }}">{{ $c->customer_unique_id }}</a></td>
                            <td>
                                <strong>{{ $c->customer_name ?: 'Unnamed' }}</strong>
                                <div class="customer-meta text-muted">{{ $c->email ?: 'No email' }}</div>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $c->pppUser?->service === 'static' ? 'info' : 'secondary' }}">{{ strtoupper($c->pppUser?->service ?: 'N/A') }}</span>
                                <div class="customer-meta">{{ $connection }}</div>
                            </td>
                            <td>
                                @if($c->mobile)
                                    @php($dialNumber = preg_replace('/[^0-9+]/', '', (string) $c->mobile))
                                    <span class="d-inline-flex align-items-center gap-2 text-nowrap">
                                        <span>{{ $c->mobile }}</span>
                                        <a class="btn btn-sm btn-outline-success py-1 px-2" href="tel:{{ $dialNumber }}" aria-label="Call {{ $c->mobile }}" title="Call customer"><i class="bi bi-telephone-fill" aria-hidden="true"></i> Call</a>
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $c->package->package ?? $c->pppUser->package_name ?? '—' }}</td>
                            <td>{{ $c->pppUser->router_name ?? '—' }}</td>
                            <td>
                                <div>Bill: {{ number_format($c->billing?->total_amount ?? 0, 2) }} ৳</div>
                                <div class="{{ ($c->billing?->due_amount ?? 0) > 0 ? 'text-danger' : 'text-success' }}">Due: {{ number_format($c->billing?->due_amount ?? 0, 2) }} ৳</div>
                            </td>
                            <td>
                                <div>{{ $address ?: ($c->address ?: '—') }}</div>
                                @if($c->pppUser?->router_name)<div class="customer-meta text-muted">{{ $c->pppUser->router_name }}</div>@endif
                            </td>
                            <td>@if($mode === 'online' && $status === 'active' && strtolower((string) ($c->pppUser?->status ?? '')) === 'active')<span class="customer-live-pulse" title="Confirmed by live MikroTik PPP session" aria-label="Live session confirmed"></span>@endif<span class="badge text-bg-{{ $statusClass }}">{{ ucfirst($status) }}</span></td>
                        @endif
                        <td class="customer-actions">
                            <div class="customer-diagnostics">
                                <a class="btn btn-outline-secondary btn-sm" title="View" href="{{ route('customers.show', $customerId) }}"><i class="bi bi-eye"></i></a>
                                @if($mode !== 'online' && (auth()->user()->hasRole('Super Admin') || hasAccess(['Super Admin'], ['edit-customer'])))
                                    <a class="btn btn-primary btn-sm" title="Edit" href="{{ route('customers.edit', $customerId) }}"><i class="bi bi-pencil-square"></i></a>
                                @endif
                                @if($mode === 'online')
                                    <button type="button" class="btn btn-outline-info btn-sm js-customer-ping" title="Ping Check" data-url="{{ route('broadband-customer-ping', $customerId) }}">
                                        <i class="bi bi-broadcast-pin"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm js-customer-traffic" title="Real-Time Traffic" data-url="{{ route('broadband-customer-traffic', $customerId) }}" data-customer="{{ $c->customer_unique_id }}" data-username="{{ $c->pppUser->username ?? '—' }}">
                                        <i class="bi bi-activity"></i>
                                    </button>
                                @endif
                                @if($status === 'active' && (auth()->user()->hasRole('Super Admin') || hasAccess(['Super Admin'], ['disable-customer'])))
                                    <form method="POST" action="{{ route('broadband-customer-disable', $customerId) }}" onsubmit="const reason=prompt('Left reason (optional):'); if(reason===null)return false; this.querySelector('[name=left_reason]').value=reason; return confirm('Disable this customer and move to Left Customers?')">
                                        @csrf
                                        <input type="hidden" name="left_reason" value="">
                                        <button class="btn btn-warning btn-sm" title="Disable / Mark Left"><i class="bi bi-pause-circle"></i></button>
                                    </form>
                                @endif
                                @if($status !== 'active' && (auth()->user()->hasRole('Super Admin') || hasAccess(['Super Admin'], ['delete-customer'])))
                                    <form method="POST" action="{{ route('broadband-customer-destroy', $customerId) }}" onsubmit="return confirm('Delete this customer record? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </div>
                            @if($mode === 'inactive' && (auth()->user()->hasRole('Super Admin') || hasAccess(['Super Admin'], ['edit-customer'])))
                                <details class="mt-2" style="min-width:230px">
                                    <summary class="small text-primary" style="cursor:pointer">Edit left/recovery details</summary>
                                    <form method="POST" action="{{ route('broadband-customer-left-details', $customerId) }}" class="border rounded p-2 mt-2 bg-light">
                                        @csrf @method('PATCH')
                                        <label class="form-label small mb-1">Zone</label>
                                        <input class="form-control form-control-sm mb-2" name="zone_name" maxlength="120" value="{{ $c->zone_name }}" placeholder="Zone / area">
                                        <label class="form-label small mb-1">Server / Router</label>
                                        <input class="form-control form-control-sm mb-2" name="server_label" maxlength="160" value="{{ $c->server_label ?: ($c->pppUser->router_name ?? '') }}">
                                        <label class="form-label small mb-1">Left date</label>
                                        <input class="form-control form-control-sm mb-2" type="date" name="left_date" value="{{ $c->left_date?->format('Y-m-d') }}">
                                        <label class="form-label small mb-1">Products / equipment</label>
                                        <textarea class="form-control form-control-sm mb-2" name="left_equipment" rows="2" maxlength="5000" placeholder="ONU, router, adapter, cable...">{{ $c->left_equipment }}</textarea>
                                        <label class="form-label small mb-1">Recovery status</label>
                                        <select class="form-select form-select-sm mb-2" name="recovery_status" required>
                                            @foreach(['pending'=>'Pending','partial'=>'Partially recovered','recovered'=>'Recovered','not_required'=>'Not required','issue'=>'Recovery issue'] as $value=>$label)
                                                <option value="{{ $value }}" @selected(($c->recovery_status ?: 'pending') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="form-label small mb-1">Left reason</label>
                                        <textarea class="form-control form-control-sm mb-2" name="left_reason" rows="2" maxlength="2000">{{ $c->left_reason }}</textarea>
                                        <button class="btn btn-primary btn-sm w-100">Save details</button>
                                    </form>
                                </details>
                            @endif
                            @if($mode === 'online')
                                <div class="customer-diagnostic-result text-muted js-ping-result" aria-live="polite"></div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'inactive' ? 14 : 10 }}" class="text-center py-5 text-muted">No customers found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="text-muted small">Showing {{ $customers->firstItem() ?: 0 }}–{{ $customers->lastItem() ?: 0 }} of {{ $customers->total() }}</span>
            {{ $customers->links() }}
        </div>
    </div>
@if($mode === 'online')
<div class="modal fade" id="customerTrafficModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Broadband</div>
                    <h5 class="modal-title mb-0"><span id="trafficCustomer">Customer</span> · Live Traffic</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="small text-muted">PPPoE: <strong id="trafficUsername">—</strong></div>
                    <div class="small text-muted">Router: <strong id="trafficRouter">—</strong></div>
                </div>
                <div id="trafficError" class="alert alert-warning py-2 d-none"></div>
                <div class="row g-2">
                    <div class="col-6"><div class="traffic-metric text-success"><small>Download</small><strong id="trafficDownload">0 bps</strong></div></div>
                    <div class="col-6"><div class="traffic-metric text-primary"><small>Upload</small><strong id="trafficUpload">0 bps</strong></div></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3 small text-muted">
                    <span><span class="traffic-live-dot text-success"></span> Live</span>
                    <span id="trafficUpdated">Waiting for data…</span>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(() => {
    const trafficModal = document.getElementById('customerTrafficModal');
    const downloadEl = document.getElementById('trafficDownload');
    const uploadEl = document.getElementById('trafficUpload');
    const updatedEl = document.getElementById('trafficUpdated');
    const errorEl = document.getElementById('trafficError');
    const customerEl = document.getElementById('trafficCustomer');
    const usernameEl = document.getElementById('trafficUsername');
    const routerEl = document.getElementById('trafficRouter');

    const formatBits = (bits) => {
        const value = Number(bits || 0);
        if (value >= 1000000000) return (value / 1000000000).toFixed(2) + ' Gbps';
        if (value >= 1000000) return (value / 1000000).toFixed(2) + ' Mbps';
        if (value >= 1000) return (value / 1000).toFixed(2) + ' Kbps';
        return Math.round(value) + ' bps';
    };

    let trafficUrl = '';
    let trafficTimer = null;
    let trafficInFlight = false;

    const stopTraffic = () => {
        if (trafficTimer) {
            clearInterval(trafficTimer);
            trafficTimer = null;
        }
        trafficInFlight = false;
    };

    const pollTraffic = async () => {
        if (!trafficUrl || trafficInFlight) return;
        trafficInFlight = true;
        try {
            const response = await fetch(trafficUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Live traffic is unavailable.');
            downloadEl.textContent = formatBits(data.download_bps ?? data.tx_bps);
            uploadEl.textContent = formatBits(data.upload_bps ?? data.rx_bps);
            routerEl.textContent = data.router || '—';
            updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        } catch (error) {
            errorEl.textContent = error.message || 'Live traffic is unavailable.';
            errorEl.classList.remove('d-none');
        } finally {
            trafficInFlight = false;
        }
    };

    document.querySelectorAll('.js-customer-ping').forEach((button) => {
        button.addEventListener('click', async () => {
            const result = button.closest('td').querySelector('.js-ping-result');
            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            try {
                const response = await fetch(button.dataset.url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await response.json();
                if (data.ok) {
                    result.className = 'customer-diagnostic-result text-success js-ping-result';
                    result.textContent = 'Ping: ' + Number(data.latency_ms || 0).toFixed(2) + ' ms';
                    result.title = data.ip || '';
                } else {
                    result.className = 'customer-diagnostic-result text-danger js-ping-result';
                    result.textContent = data.message || 'Ping failed';
                }
            } catch (error) {
                result.className = 'customer-diagnostic-result text-danger js-ping-result';
                result.textContent = error.message || 'Ping check failed';
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    });

    document.querySelectorAll('.js-customer-traffic').forEach((button) => {
        button.addEventListener('click', () => {
            trafficUrl = button.dataset.url || '';
            customerEl.textContent = button.dataset.customer || 'Customer';
            usernameEl.textContent = button.dataset.username || '—';
            routerEl.textContent = 'Loading…';
            downloadEl.textContent = '0 bps';
            uploadEl.textContent = '0 bps';
            updatedEl.textContent = 'Waiting for data…';
            errorEl.classList.add('d-none');

            if (window.bootstrap && trafficModal) {
                window.bootstrap.Modal.getOrCreateInstance(trafficModal).show();
            }

            stopTraffic();
            pollTraffic();
            trafficTimer = setInterval(pollTraffic, 2000);
        });
    });

    if (trafficModal) trafficModal.addEventListener('hidden.bs.modal', stopTraffic);
})();
</script>
@endpush
@endif
</div>
</x-app-layout>
