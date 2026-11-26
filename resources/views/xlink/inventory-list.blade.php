<x-app-layout>
<div class="container-fluid px-1">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div><h3 class="mb-1"><i class="bi bi-hdd-network me-2"></i>{{ $label }} Inventory</h3><div class="text-muted">Database-backed device inventory</div></div>
    <a class="btn btn-outline-secondary" href="{{ route('network-inventory') }}">Back to Inventory</a>
  </div>
  @if(session('inventory_message')) <div class="alert alert-success">{{ session('inventory_message') }}</div> @endif
  @if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
  <div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Add {{ $label }}</strong></div>
    <div class="card-body">
      <form method="POST" action="{{ route('network-inventory.devices.store', $type) }}" class="row g-3">
        @csrf
        <div class="col-md-3"><label class="form-label">Name</label><input required name="name" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">IP</label><input name="ip_address" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Vendor</label><input name="vendor" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Model</label><input name="model" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Location</label><input name="location" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option>online</option><option>offline</option><option>unknown</option></select></div><div class="col-md-2"><label class="form-label">Health port</label><input name="health_port" class="form-control" placeholder="e.g. 80 / 443 / 8728"></div><div class="col-md-1 d-flex align-items-center"><div class="form-check mt-4"><input name="monitor_enabled" value="1" type="checkbox" class="form-check-input" id="monitor"><label class="form-check-label" for="monitor">Monitor</label></div></div>
        @if($type === 'switch')
        <div class="col-12"><div class="border rounded p-3">
          <strong>Read-only FDB collection</strong>
          <div class="small text-muted mb-3">Use SNMP v1/v2c with a read-only community. MikroTik proxy and SSH fallback are optional; SSH uses the fixed read-only vendor command allowlist.</div>
          <div class="row g-3">
            <div class="col-md-2"><label class="form-label">SNMP version</label><select name="snmp_version" class="form-select"><option value="2C" @selected(old('snmp_version','2C')==='2C')>v2c</option><option value="1" @selected(old('snmp_version')==='1')>v1</option></select></div>
            <div class="col-md-2"><label class="form-label">SNMP port</label><input type="number" min="1" max="65535" name="snmp_port" value="{{ old('snmp_port',161) }}" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Read-only SNMP community</label><input type="password" name="snmp_community" autocomplete="new-password" class="form-control" placeholder="Dedicated read-only community"></div>
            <div class="col-md-4"><label class="form-label">MikroTik SNMP proxy</label><select name="bridge_fdb_proxy_router_id" class="form-select"><option value="">Direct device SNMP</option>@foreach($proxyRouters as $router)<option value="{{ $router->id }}" @selected((string)old('bridge_fdb_proxy_router_id')===(string)$router->id)>{{ $router->router_name }} · RouterOS API</option>@endforeach</select></div>
            <div class="col-12"><div class="form-check"><input name="ssh_enabled" value="1" type="checkbox" class="form-check-input" id="switch-ssh-enabled" @checked(old('ssh_enabled'))><label class="form-check-label" for="switch-ssh-enabled">Enable SSH read-only CLI fallback</label></div></div>
            <div class="col-md-4"><label class="form-label">SSH username</label><input name="username" class="form-control" autocomplete="off" value="{{ old('username') }}"></div>
            <div class="col-md-4"><label class="form-label">SSH password</label><input type="password" name="password" class="form-control" autocomplete="new-password"></div>
            <div class="col-md-2"><label class="form-label">SSH port</label><input type="number" min="1" max="65535" name="ssh_port" value="{{ old('ssh_port',22) }}" class="form-control"></div>
          </div>
        </div></div>
        @endif
        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control"></div>
        <div class="col-12"><button class="btn btn-primary">Add Device</button></div>
      </form>
    </div>
  </div>
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-5"><label class="form-label">Search</label><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Name, IP, vendor, model or location"></div>
        <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All statuses</option><option value="online" @selected(request('status')==='online')>Online</option><option value="offline" @selected(request('status')==='offline')>Offline</option><option value="unknown" @selected(request('status')==='unknown')>Unknown</option></select></div>
        <div class="col-md-2"><label class="form-label">Per page</label><select name="per_page" class="form-select"><option value="10" @selected((int)request('per_page',10)===10)>10</option><option value="25" @selected((int)request('per_page',10)===25)>25</option><option value="50" @selected((int)request('per_page',10)===50)>50</option></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Filter</button><a href="{{ url()->current() }}" class="btn btn-outline-secondary">Reset</a></div>
      </form>
    </div>
  </div>
  <form method="POST" action="{{ route('network-inventory.devices.bulk-status', $type) }}" class="card shadow-sm mb-3">
    @csrf
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <span class="fw-semibold me-2">Bulk status:</span>
      <select name="status" class="form-select form-select-sm" style="max-width:160px"><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Unknown</option></select>
      <button class="btn btn-sm btn-outline-primary">Apply to selected</button>
    </div>
  </form>
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between"><strong>{{ $devices->total() }} devices</strong><span class="text-muted">Search, edit and manage inventory</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.inventory-select').forEach(e=>e.checked=this.checked)"></th><th>Name</th><th>IP</th><th>Vendor / Model</th><th>Status</th><th>Health</th><th>Location</th><th>Action</th></tr></thead><tbody>
      @forelse($devices as $device)
        <tr>
          <td><input class="inventory-select" type="checkbox" name="device_ids[]" value="{{ $device->id }}"></td>
          <td><input class="form-control form-control-sm" value="{{ $device->name }}" readonly></td>
          <td>{{ $device->ip_address ?: '—' }}</td>
          <td>{{ trim(($device->vendor ?: '—').' / '.($device->model ?: '—')) }}</td>
          <td><span class="badge {{ $device->status==='online'?'bg-success':($device->status==='offline'?'bg-danger':'bg-secondary') }}">{{ ucfirst($device->status ?: 'unknown') }}</span></td>
          <td><span class="badge {{ in_array($device->health_status,['ready','up','online'])?'bg-success':($device->health_status?'bg-warning text-dark':'bg-secondary') }}">{{ ucfirst($device->health_status ?: 'not checked') }}</span><div class="small text-muted mt-1">{{ $device->health_port ? 'Port '.$device->health_port : 'No port' }} · Monitor {{ $device->monitor_enabled ? 'On' : 'Off' }}</div></td>
          <td>{{ $device->location ?: '—' }}</td>
          <td class="text-nowrap">
            @if(in_array($type, ['olt', 'switch'], true))
              <a class="btn btn-sm btn-outline-info" href="{{ route('network-inventory.device.bridge-fdb', $device) }}" title="Read-only L2 Bridge Forwarding Database">FDB</a>
            @endif
            <a class="btn btn-sm btn-outline-primary" href="{{ route('network-inventory.devices', [$type,'q'=>$device->name]) }}">Edit</a>
            <form method="POST" action="{{ route('network-inventory.devices.destroy', [$type, $device->id]) }}" class="d-inline" onsubmit="return confirm('Delete this inventory record?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="text-center text-muted py-4">No {{ strtolower($label) }} records match the current filter.</td></tr>
      @endforelse
    </tbody></table></div>
    <div class="card-footer">{{ $devices->links() }}</div>
  </div>
  </form>
</div>
</x-app-layout>
