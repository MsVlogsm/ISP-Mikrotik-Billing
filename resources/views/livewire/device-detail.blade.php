<div class="container-fluid px-1 pb-4">
<style>
.dd-card{background:#fff;border:1px solid #e7edf3;border-radius:14px;box-shadow:0 5px 22px rgba(31,41,55,.06)}
.dd-kpi{padding:18px;min-height:112px}.dd-kpi .num{font-size:27px;font-weight:800}.dd-muted{color:#64748b;font-size:.84rem}
.dd-row{padding:12px 16px;border-top:1px solid #edf1f5}.dd-table{width:100%;border-collapse:collapse}.dd-table th,.dd-table td{padding:10px 12px;border-bottom:1px solid #edf1f5;font-size:.84rem;vertical-align:middle}
.dd-section{padding:15px 16px;border-bottom:1px solid #edf1f5;font-weight:700}.dd-badge{font-size:.72rem}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
 <div><a href="{{ route('device-manager') }}" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Device Manager</a><h3 class="mb-0 mt-1">{{ $name }}</h3><small class="dd-muted">Unified device workspace · diagnostics, health, alerts and ONU/customer relationship</small></div>
 <div class="d-flex gap-2"><a href="{{ route('network-map') }}" class="btn btn-outline-secondary"><i class="bi bi-diagram-3 me-1"></i>Network Map</a><a href="{{ route('network-events') }}" class="btn btn-outline-warning"><i class="bi bi-bell me-1"></i> Events</a><button wire:click="probe" wire:loading.attr="disabled" class="btn btn-success"><i class="bi bi-activity me-1"></i> <span wire:loading.remove>Probe now</span><span wire:loading>Probing…</span></button></div>
</div>
@if($message)<div class="alert alert-info py-2">{{ $message }}</div>@endif
<div class="row g-3 mb-3">
 <div class="col-6 col-xl-3"><div class="dd-card dd-kpi"><small class="dd-muted">Status</small><div class="num mt-2">{{ $router ? ($router->action ?: 'unknown') : ($device->health_status ?: $device->status) }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="dd-card dd-kpi"><small class="dd-muted">Latency</small><div class="num mt-2">{{ ($device?->last_latency_ms ?? $router?->last_latency_ms) !== null ? ($device?->last_latency_ms ?? $router?->last_latency_ms).' ms' : '—' }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="dd-card dd-kpi"><small class="dd-muted">Open alerts</small><div class="num mt-2 text-danger">{{ $openEvents }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="dd-card dd-kpi"><small class="dd-muted">ONU / Customers</small><div class="num mt-2">{{ $device?->type === 'olt' ? $mappingOnline.'/'.$mappingTotal : '—' }}</div><small class="dd-muted">{{ $device?->type === 'olt' ? 'Online / total ONU mappings' : '' }}</small></div></div>
</div>
<div class="row g-3">
 <div class="col-xl-4">
  <div class="dd-card mb-3"><div class="dd-section">Device Identity</div>
   <div class="dd-row"><span class="dd-muted">Type</span><div>{{ $device?->type ? strtoupper(str_replace('-', ' ', $device->type)) : 'MIKROTIK' }}</div></div>
   <div class="dd-row"><span class="dd-muted">Vendor / Model</span><div>{{ $device?->vendor ?: 'MikroTik' }} · {{ $device?->model ?: '—' }}</div></div>
   <div class="dd-row"><span class="dd-muted">Management IP / Probe Port</span><div>{{ $device?->ip_address ?: $device?->host ?: $router?->ip_address ?: '—' }}:{{ $device?->type === 'olt' ? ($device?->snmp_port ?: 161) : ($device?->health_port ?: $device?->port ?: $router?->api_port ?: '—') }} <small class="dd-muted">{{ $device?->type === 'olt' ? 'SNMP/UDP' : ($router ? 'MikroTik API' : 'TCP') }}</small></div></div>
   <div class="dd-row"><span class="dd-muted">Location</span><div>{{ $device?->location ?: $router?->location ?: '—' }}</div></div>
   <div class="dd-row"><span class="dd-muted">Firmware / Serial</span><div>{{ $device?->firmware ?: '—' }} · {{ $device?->serial_no ?: '—' }}</div></div>
   @if($device?->type === 'olt')
   <div class="dd-row"><span class="dd-muted">PON / GE / SFP</span><div>{{ $device->pon_ports ?? 0 }} / {{ $device->ge_ports ?? 0 }} / {{ $device->sfp_ports ?? 0 }}</div></div>
   <div class="dd-row"><span class="dd-muted">ONU inventory</span><div>{{ $device->onu_online ?? 0 }} online / {{ $device->onu_total ?? 0 }} total</div></div>
   @endif
  </div>
  <div class="dd-card"><div class="dd-section">Health History</div><div class="table-responsive"><table class="dd-table"><thead><tr><th>Time</th><th>Status</th><th>Latency</th></tr></thead><tbody>
   @forelse($health as $h)<tr><td>{{ optional($h->checked_at)->format('d M H:i:s') }}</td><td><span class="badge {{ $h->status==='online'?'bg-success':($h->status==='down'?'bg-danger':'bg-secondary') }}">{{ ucfirst($h->status) }}</span></td><td>{{ $h->latency_ms }} ms</td></tr>@empty<tr><td colspan="3" class="text-center text-muted py-4">No health history.</td></tr>@endforelse
  </tbody></table></div></div>
 </div>
 <div class="col-xl-8">
  <div class="dd-card mb-3"><div class="dd-section d-flex justify-content-between"><span>Active / Recent Alerts</span><a href="{{ route('network-events') }}" class="small">Open alert centre</a></div><div class="table-responsive"><table class="dd-table"><thead><tr><th>Severity</th><th>Alert</th><th>Status</th><th>Last seen</th><th></th></tr></thead><tbody>
   @forelse($events as $event)<tr><td><span class="badge {{ $event->severity==='critical'?'bg-danger':($event->severity==='warning'?'bg-warning text-dark':'bg-primary') }}">{{ ucfirst($event->severity) }}</span></td><td><strong>{{ $event->title }}</strong><div class="dd-muted">{{ Str::limit($event->message,110) }}</div></td><td>{{ ucfirst($event->status) }}@if($event->status==='acknowledged')<div class="dd-muted">{{ $event->acknowledgedBy?->name ?: 'Acknowledged' }}</div>@elseif($event->status==='resolved')<div class="dd-muted">{{ $event->resolvedBy?->name ?: 'Monitor' }}</div>@endif</td><td>{{ optional($event->last_seen_at)->format('d M H:i:s') }}</td><td>@if($event->status==='open')<button wire:click="acknowledge({{ $event->id }})" class="btn btn-sm btn-outline-warning">ACK</button>@elseif($event->status==='acknowledged')<button wire:click="resolve({{ $event->id }})" class="btn btn-sm btn-outline-success">Resolve</button>@endif</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">No alerts for this device.</td></tr>@endforelse
  </tbody></table></div></div>
  @if($device?->type === 'olt')
  <div class="dd-card"><div class="dd-section d-flex justify-content-between"><span>ONU / Customer Relationship</span><a href="{{ route('network-inventory.olt.customers',$device) }}" class="small">Open full ledger</a></div><div class="table-responsive"><table class="dd-table"><thead><tr><th>Customer</th><th>PPPoE</th><th>ONU</th><th>PON</th><th>Status</th><th>RX / TX</th></tr></thead><tbody>
   @forelse($mappings as $m)<tr><td><strong>@if(optional($m->customer)->customer_unique_id)<a href="{{ route('customer.details', encrypt($m->customer->customer_unique_id)) }}">{{ $m->customer->customer_name ?: $m->customer->customer_unique_id }}</a>@else{{ optional($m->customer)->customer_name ?: 'Unmapped' }}@endif</strong><div class="dd-muted">{{ optional($m->customer)->mobile }}</div></td><td>{{ optional($m->pppUser)->username ?: '—' }}</td><td>{{ $m->onu_id ?: '—' }}<div class="dd-muted">{{ $m->onu_serial ?: $m->onu_mac ?: '' }}</div></td><td>{{ $m->pon_port ?: '—' }}</td><td><span class="badge {{ $m->status==='online'?'bg-success':($m->status==='offline'?'bg-danger':'bg-secondary') }}">{{ ucfirst($m->status) }}</span></td><td>{{ $m->rx_power !== null ? $m->rx_power.' dBm' : '—' }} / {{ $m->tx_power !== null ? $m->tx_power.' dBm' : '—' }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No ONU/customer mappings found.</td></tr>@endforelse
  </tbody></table></div></div>
  @endif
 </div>
</div>
</div>
