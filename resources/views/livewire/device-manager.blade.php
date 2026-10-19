<div class="device-manager container-fluid px-1 pb-4">
<style>
.device-manager .dm-card{background:#fff;border:1px solid #e8edf3;border-radius:14px;box-shadow:0 5px 20px rgba(31,41,55,.06)}
.device-manager .dm-stat{min-height:132px;padding:18px}.device-manager .dm-stat .icon{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:21px;background:#f1f5f9}
.device-manager .dm-stat .num{font-size:30px;font-weight:800;line-height:1}.device-manager .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.device-manager .device-row{padding:15px 18px;border-top:1px solid #edf1f5}.device-manager .device-row:hover{background:#fafcff}
.device-manager .device-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:7px}.device-manager .online{background:#16a34a}.device-manager .offline{background:#dc2626}.device-manager .degraded{background:#f59e0b}.device-manager .unknown{background:#94a3b8}.device-manager .alert-dot{background:#dc2626}
.device-manager .device-meta{color:#64748b;font-size:.82rem}.device-manager .pill{border:1px solid #dce4ed;border-radius:20px;padding:4px 9px;background:#fff;font-size:.75rem}
@media(max-width:768px){.device-manager .dm-stat{min-height:112px}.device-manager .device-row{padding:13px 10px}}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><span class="text-uppercase small text-muted fw-semibold">Network Centre</span><h3 class="mb-0">Device Manager</h3><small class="text-muted">Live availability, latency, customer presence, alerts and device workspaces.</small></div>
    <div class="toolbar">
        <button class="btn btn-outline-dark" wire:click="refreshBoard"><i class="bi bi-arrow-repeat me-1"></i>Refresh Board</button>
        <a class="btn btn-outline-primary" href="{{ route('network-map') }}"><i class="bi bi-diagram-3 me-1"></i>Network Map</a>
        <a class="btn btn-outline-info" href="{{ route('optical-audit') }}"><i class="bi bi-activity me-1"></i>Optical Audit</a>
        <a class="btn btn-outline-primary" href="{{ route('network-inventory') }}"><i class="bi bi-hdd-network me-1"></i>Inventory</a>
        <a class="btn btn-outline-warning" href="{{ route('network-events') }}"><i class="bi bi-bell me-1"></i>Events <span class="badge bg-danger">{{ $criticalAlerts }}</span></a>
        <button class="btn btn-primary" wire:click="$set('showAddDevice', true)"><i class="bi bi-plus-lg me-1"></i>Add New Device</button>
    </div>
</div>

@if($message)<div class="alert alert-info py-2">{{ $message }}</div>@endif

<div class="row g-3 mb-3">
    <div class="col-6 col-xl"><div class="dm-card dm-stat"><div class="d-flex justify-content-between"><div><small class="text-muted text-uppercase">Total Devices</small><div class="num mt-2">{{ $total }}</div><small class="text-muted">{{ $attention }} need attention</small></div><div class="icon"><i class="bi bi-hdd-stack"></i></div></div></div></div>
    <div class="col-6 col-xl"><div class="dm-card dm-stat"><div class="d-flex justify-content-between"><div><small class="text-muted text-uppercase">Online Devices</small><div class="num mt-2 text-success">{{ $online }}</div><small class="text-muted">Latest saved status</small></div><div class="icon"><i class="bi bi-wifi"></i></div></div></div></div>
    <div class="col-6 col-xl"><div class="dm-card dm-stat"><div class="d-flex justify-content-between"><div><small class="text-muted text-uppercase">Critical Alerts</small><div class="num mt-2 {{ $criticalAlerts ? 'text-danger' : 'text-success' }}">{{ $criticalAlerts }}</div><small class="text-muted">Open / acknowledged</small></div><div class="icon"><i class="bi bi-exclamation-triangle"></i></div></div></div></div>
    <div class="col-6 col-xl"><div class="dm-card dm-stat"><div class="d-flex justify-content-between"><div><small class="text-muted text-uppercase">Average Latency</small><div class="num mt-2">{{ $avgLatency !== null ? $avgLatency.' ms' : '—' }}</div><small class="text-muted">Devices with snapshots</small></div><div class="icon"><i class="bi bi-speedometer2"></i></div></div></div></div>
    <div class="col-12 col-xl"><div class="dm-card dm-stat"><div class="d-flex justify-content-between"><div><small class="text-muted text-uppercase">Customers</small><div class="num mt-2">{{ number_format($customerCount) }}</div><small class="text-muted">{{ number_format($activeCustomerCount) }} active · {{ number_format($inactiveCustomerCount) }} inactive</small></div><div class="icon"><i class="bi bi-people"></i></div></div></div></div>
</div>

<div class="dm-card mb-3">
    <div class="p-3 border-bottom">
        <div class="toolbar">
            <div class="input-group" style="max-width:360px"><span class="input-group-text"><i class="bi bi-search"></i></span><input wire:model.live.debounce.300ms="search" class="form-control" placeholder="Search device, IP, vendor, model..."></div>
            <select wire:model.live="type" class="form-select" style="max-width:180px"><option value="">All types</option>@foreach($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('-', ' ', $t)) }}</option>@endforeach</select>
            <select wire:model.live="status" class="form-select" style="max-width:210px"><option value="">All status</option><option value="online">Online</option><option value="offline">Offline</option><option value="authentication_failed">Authentication Failed</option><option value="credentials_missing">Credentials Missing</option><option value="api_error">API Error</option><option value="degraded">Degraded</option><option value="unknown">Unknown</option></select>
            <select wire:model.live="location" class="form-select" style="max-width:180px"><option value="">All locations</option>@foreach($locations as $l)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select>
            <span class="pill ms-auto"><i class="bi bi-clock-history me-1"></i>Snapshots updated by scheduled monitoring</span>
        </div>
    </div>
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center"><div><div class="fw-bold fs-5">Device Health Board</div><small class="text-muted">Open a device or run a read-only connectivity probe. OLTs are checked using SNMP/UDP; switches and access points use their configured TCP port. Probing does not change device configuration.</small></div><span class="badge bg-secondary">{{ $rows->count() }} shown</span></div>

    @forelse($rows as $row)
        <div class="device-row d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1" style="min-width:230px">
                <div class="fw-bold"><span class="device-dot {{ $row['status'] === 'online' ? 'online' : (in_array($row['status'], ['offline','authentication_failed','credentials_missing','api_error'], true) ? 'offline' : ($row['status']==='degraded' ? 'degraded' : 'unknown')) }}"></span>{{ $row['name'] }}</div>
                <div class="device-meta mt-1">{{ $row['type_label'] }} · {{ $row['vendor'] }} · {{ $row['model'] }}</div>
            </div>
            <div style="min-width:165px"><div class="small fw-semibold">{{ $row['host'] ?: 'Host not configured' }}{{ $row['host'] ? ':'.$row['port'] : '' }}</div><div class="device-meta">{{ $row['protocol'] }} · {{ $row['location'] }}</div></div>
            <div style="min-width:95px"><span class="badge {{ $row['status']==='online'?'bg-success':(in_array($row['status'], ['offline','authentication_failed','credentials_missing','api_error','degraded'], true)?'bg-danger':'bg-secondary') }}">{{ ucfirst(str_replace('_',' ',$row['status'])) }}</span></div>
            <div style="min-width:82px"><div class="small fw-semibold">{{ $row['latency'] !== null ? $row['latency'].' ms' : '—' }}</div><div class="device-meta">Latency</div></div>
            <div style="min-width:70px"><span class="badge {{ $row['alerts'] ? 'bg-danger' : 'bg-light text-dark' }}">{{ $row['alerts'] }} alerts</span><div class="device-meta mt-1">Last: {{ $row['seen'] ? \Illuminate\Support\Carbon::parse($row['seen'])->diffForHumans() : 'Never checked' }}</div></div>
            <div class="d-flex gap-1 ms-auto">
                <button wire:click="{{ $row['type']==='mikrotik' ? 'probeRouter('.$row['id'].')' : 'probeDevice('.$row['id'].')' }}" wire:loading.attr="disabled" class="btn btn-sm btn-outline-success" title="Read-only connectivity probe"><i class="bi bi-activity"></i><span class="d-none d-lg-inline ms-1">Probe</span></button>
                <a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-primary" title="Open device"><i class="bi bi-box-arrow-up-right"></i><span class="d-none d-lg-inline ms-1">Open</span></a>
            </div>
        </div>
    @empty
        <div class="p-5 text-center text-muted"><i class="bi bi-hdd-network fs-1 d-block mb-2"></i>No devices match the current filters.</div>
    @endforelse
</div>

@if($showAddDevice)
<div class="modal d-block" tabindex="-1" style="background:rgba(15,23,42,.45)" role="dialog" aria-modal="true">
 <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
  <form wire:submit.prevent="createDevice">
   <div class="modal-header"><div><h5 class="modal-title">Add Network Device</h5><small class="text-muted">Creates an inventory record only. No device is contacted.</small></div><button type="button" class="btn-close" wire:click="$set('showAddDevice', false)"></button></div>
   <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Device type</label><select wire:model="newDeviceType" class="form-select"><option value="switch">Switch</option><option value="access-point">Access Point</option></select>@error('newDeviceType')<small class="text-danger">{{ $message }}</small>@enderror</div>
    <div class="col-md-6"><label class="form-label">Device name *</label><input wire:model="newDeviceName" class="form-control" required>@error('newDeviceName')<small class="text-danger">{{ $message }}</small>@enderror</div>
    <div class="col-md-6"><label class="form-label">Management IP</label><input wire:model="newDeviceIp" class="form-control" placeholder="192.0.2.10">@error('newDeviceIp')<small class="text-danger">{{ $message }}</small>@enderror</div>
    <div class="col-md-6"><label class="form-label">Management TCP port</label><input wire:model="newDevicePort" type="number" min="1" max="65535" class="form-control" required>@error('newDevicePort')<small class="text-danger">{{ $message }}</small>@enderror</div>
    <div class="col-md-6"><label class="form-label">Vendor</label><input wire:model="newDeviceVendor" class="form-control" placeholder="Cisco, Huawei, TP-Link..."></div>
    <div class="col-md-6"><label class="form-label">Model</label><input wire:model="newDeviceModel" class="form-control"></div>
    <div class="col-12"><label class="form-label">POP / Location</label><input wire:model="newDeviceLocation" class="form-control"></div>
    <div class="col-12"><label class="form-check"><input type="checkbox" wire:model="newDeviceMonitorEnabled" class="form-check-input"> Enable scheduled health monitoring</label></div>
   </div><div class="alert alert-info mt-3 mb-0 small">For OLTs, use the full OLT setup form so management credentials, vendor/model, ports and command profile can be configured safely.</div></div>
   <div class="modal-footer"><a href="{{ route('network-inventory.olt.add') }}" class="btn btn-outline-secondary me-auto">Add OLT instead</a><button type="button" class="btn btn-light" wire:click="$set('showAddDevice', false)">Cancel</button><button type="submit" class="btn btn-primary">Save device</button></div>
  </form>
 </div></div>
</div>
@endif
</div>
