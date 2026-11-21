<x-app-layout>
    <div class="container-fluid px-3 px-xl-4 py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('network-inventory') }}">Network Inventory</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('network-inventory.olt') }}">OLT Management</a>
            <a class="btn btn-sm btn-primary" href="{{ route('network-inventory.universal-search') }}">Universal Search</a>
        </div>
        <div class="mb-3">
            <div class="text-muted small">NETWORK INTELLIGENCE</div>
            <h1 class="h3 mb-1"><i class="bi bi-search me-2"></i>Universal Customer / Network Search</h1>
            <p class="text-muted mb-0">Search customer, PPPoE, IP, MAC, ONU serial and live RouterOS session/ARP information together.</p>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('network-inventory.universal-search') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-7">
                        <label for="universal-search-q" class="form-label">Search value</label>
                        <input id="universal-search-q" name="q" value="{{ $query }}" class="form-control form-control-lg" placeholder="Customer ID, PPPoE username, IP, MAC or ONU serial" maxlength="120" required>
                    </div>
                    <div class="col-8 col-lg-3">
                        <label for="universal-search-type" class="form-label">Search by</label>
                        <select id="universal-search-type" name="type" class="form-select form-select-lg">
                            @foreach(['all'=>'All fields','pppoe'=>'PPPoE username','ip'=>'IP address','mac'=>'MAC address','serial'=>'ONU serial','name'=>'Customer name / ID'] as $value => $label)
                                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4 col-lg-2 d-grid">
                        <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>

        @if($submitted)
            <div class="alert alert-info">
                Read-only search completed for <strong>{{ $query }}</strong>. Live PPP session and ARP lookups were requested from {{ count($routerChecks) }} connected RouterOS router(s). No device configuration was changed.
            </div>

            @if($customers->isNotEmpty())
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body fw-semibold">Customer records <span class="badge bg-secondary">{{ $customers->count() }}</span></div>
                    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Customer</th><th>ID / Mobile</th><th>PPPoE</th><th>Router</th><th>Status</th></tr></thead>
                        <tbody>@foreach($customers as $customer)
                            <tr><td><strong>{{ $customer->customer_name }}</strong></td><td>{{ $customer->customer_unique_id }}<small class="d-block text-muted">{{ $customer->mobile }}</small></td><td>{{ $customer->pppUser?->username ?: '—' }}</td><td>{{ $customer->pppUser?->router_name ?: '—' }}</td><td>{{ ucfirst((string) $customer->status) }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </section>
            @endif

            @if($pppUsers->isNotEmpty())
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body fw-semibold">PPPoE subscriber records <span class="badge bg-secondary">{{ $pppUsers->count() }}</span></div>
                    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                        <thead><tr><th>PPPoE username</th><th>Customer</th><th>Router</th><th>Stored IP</th><th>Caller ID / MAC</th><th>Status</th></tr></thead>
                        <tbody>@foreach($pppUsers as $ppp)
                            <tr><td><strong>{{ $ppp->username }}</strong></td><td>{{ $ppp->customer?->customer_name ?: '—' }}<small class="d-block text-muted">{{ $ppp->customer?->customer_unique_id }}</small></td><td>{{ $ppp->router_name }}</td><td>{{ $ppp->ppp_remote_ip ?: '—' }}</td><td>{{ $ppp->caller_id ?: $ppp->last_caller_id ?: '—' }}</td><td>{{ ucfirst((string) $ppp->status) }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </section>
            @endif

            @if($onuMappings->isNotEmpty() || $onuLinks->isNotEmpty())
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body fw-semibold">OLT / ONU identity <span class="badge bg-secondary">{{ $onuMappings->count() + $onuLinks->count() }}</span></div>
                    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                        <thead><tr><th>OLT / PON / ONU</th><th>MAC / Serial / IP</th><th>Customer</th><th>PPPoE</th><th>Status</th><th>Identity</th></tr></thead>
                        <tbody>
                        @foreach($onuMappings as $mapping)
                            <tr><td><strong>{{ $mapping->olt?->name ?: 'OLT' }}</strong><small class="d-block text-muted">PON {{ $mapping->pon_port }} · ONU {{ $mapping->onu_id }}</small></td><td><code>{{ $mapping->onu_mac ?: '—' }}</code><small class="d-block text-muted">{{ $mapping->onu_serial ?: '' }} {{ $mapping->onu_ip ?: '' }}</small></td><td>{{ $mapping->customer?->customer_name ?: 'Unmapped' }}<small class="d-block text-muted">{{ $mapping->customer?->customer_unique_id }}</small></td><td>{{ $mapping->pppUser?->username ?: '—' }}</td><td>{{ ucfirst((string) $mapping->status) }}</td><td>ONU mapping</td></tr>
                        @endforeach
                        @foreach($onuLinks as $link)
                            <tr><td><strong>{{ $link->onuMapping?->olt?->name ?: 'OLT' }}</strong><small class="d-block text-muted">PON {{ $link->onuMapping?->pon_port }} · ONU {{ $link->onuMapping?->onu_id }}</small></td><td><code>{{ $link->client_mac }}</code><small class="d-block text-muted">{{ $link->onuMapping?->onu_serial }}</small></td><td>{{ $link->customer?->customer_name ?: '—' }}<small class="d-block text-muted">{{ $link->customer?->customer_unique_id }}</small></td><td>{{ $link->pppUser?->username ?: '—' }}</td><td>{{ ucfirst((string) $link->status) }}</td><td>{{ $link->source }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </section>
            @endif

            @if($liveSessions->isNotEmpty())
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body fw-semibold">Live PPPoE sessions <span class="badge bg-success">{{ $liveSessions->count() }}</span></div>
                    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Router</th><th>Username</th><th>Live IP</th><th>Caller ID / MAC</th><th>Customer</th></tr></thead>
                        <tbody>@foreach($liveSessions as $session)
                            <tr><td>{{ $session['router'] }}</td><td><strong>{{ $session['username'] }}</strong></td><td>{{ $session['ip'] ?: '—' }}</td><td><code>{{ $session['mac'] ?: '—' }}</code></td><td>{{ $session['customer'] ?: '—' }}<small class="d-block text-muted">{{ $session['customer_id'] }}</small></td></tr>
                        @endforeach</tbody>
                    </table></div>
                </section>
            @endif

            @if($arpEntries->isNotEmpty())
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body fw-semibold">Live MikroTik ARP matches <span class="badge bg-secondary">{{ $arpEntries->count() }}</span></div>
                    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Router</th><th>IP address</th><th>MAC address</th><th>Interface</th><th>State</th></tr></thead>
                        <tbody>@foreach($arpEntries as $arp)
                            <tr><td>{{ $arp['router'] }}</td><td>{{ $arp['ip'] }}</td><td><code>{{ $arp['mac'] ?: '—' }}</code></td><td>{{ $arp['interface'] ?: '—' }}</td><td>{{ $arp['status'] ?: '—' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </section>
            @endif

            @if($customers->isEmpty() && $pppUsers->isEmpty() && $onuMappings->isEmpty() && $onuLinks->isEmpty() && $liveSessions->isEmpty() && $arpEntries->isEmpty())
                <div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-muted"><i class="bi bi-search fs-2 d-block mb-2"></i>No matching customer, PPPoE, ONU, MAC, IP or live router record was found.</div></div>
            @endif
        @else
            <div class="text-muted small">Search runs only after you submit a query. RouterOS information is read on demand and never cached by this page.</div>
        @endif
    </div>
</x-app-layout>
