<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="small text-muted">OLT MANAGEMENT</div>
            <strong>{{ $device->name }}</strong>
            <div class="small text-muted">{{ $device->vendor ?: 'OLT' }} {{ $device->model ?: '' }} · {{ $device->ip_address ?: $device->host ?: 'No management IP' }}</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('network-inventory.olt') }}">OLT List</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'overview' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.olt.dashboard', $device) }}">Overview</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'customers' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.olt.customers', $device) }}">ONU / Customers</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'bridge-fdb' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.device.bridge-fdb', $device) }}">L2 Bridge / FDB</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'universal-search' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.universal-search') }}">Universal Search</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'diagnostics' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.olt.diagnostics', $device) }}">Diagnostics</a>
            <a class="btn btn-sm {{ ($active ?? '') === 'config' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('network-inventory.olt.edit', $device) }}">Configuration</a>
        </div>
    </div>
</div>
