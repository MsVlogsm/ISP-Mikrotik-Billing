<x-app-layout>
@push('styles')<link rel="stylesheet" href="{{ asset('xlink-olt/olt-source.css') }}">@endpush
<div class="container-fluid px-1">
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><div><span class="text-uppercase small text-muted fw-semibold">Read-only diagnostics</span><h3 class="mb-0">OLT Diagnostics</h3><small class="text-muted">Management connectivity, saved live telemetry, ONU optical readings and SSH CLI readiness · {{ $device->name }}</small></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary" href="{{ route('network-inventory.olt.dashboard',$device) }}">Full OLT Dashboard</a><a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt.edit',$device) }}">Edit OLT</a><a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt') }}">Back</a></div></div>
<div class="alert alert-info py-2 small"><strong>What this page does:</strong> checks SNMP management reachability, reviews the latest saved OLT/ONU/OPM snapshot and verifies whether read-only SSH CLI access is available. Page load itself does not poll or change the OLT. ONU restart is a separate confirmation-controlled action.</div>
@if(session('diagnostic_message'))<div class="alert {{ str_starts_with(session('diagnostic_message'),'Connectivity check passed') ? 'alert-success' : (str_starts_with(session('diagnostic_message'),'Connectivity check not ready') ? 'alert-warning' : 'alert-danger') }}">{{ session('diagnostic_message') }}</div>@endif
@if(session('cli_check_message'))<div class="alert {{ session('cli_check_success') ? 'alert-success' : 'alert-warning' }}"><strong>SSH CLI Check:</strong> {{ session('cli_check_message') }}</div>@endif
@if(session('diagnostic_metrics_message'))<div class="alert {{ session('diagnostic_metrics_success') ? 'alert-success' : 'alert-warning' }}"><strong>Live Metrics Poll:</strong> {{ session('diagnostic_metrics_message') }}</div>@endif
<div class="row g-3">
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Connection</strong></div><div class="card-body">
<p class="mb-2"><span class="text-muted">Host:</span> <strong>{{ $device->host ?: $device->ip_address ?: '—' }}</strong></p>
<p class="mb-2"><span class="text-muted">SNMP UDP Port:</span> <strong>{{ $device->snmp_port ?: 161 }}</strong></p>
<p class="mb-2"><span class="text-muted">Web:</span> <strong>{{ $device->web_protocol ?: '—' }}{{ $device->web_port ? ':'.$device->web_port : '' }}</strong></p>
<p class="mb-3"><span class="text-muted">Status:</span> <span class="badge {{ $device->status==='online'?'bg-success':($device->status==='offline'?'bg-danger':'bg-warning text-dark') }}">{{ ucfirst($device->status ?: 'unknown') }}</span></p>
<form method="POST" action="{{ route('network-inventory.olt.diagnostic-check',$device) }}" class="mb-2">@csrf<button class="btn btn-primary w-100">Run SNMP Connectivity Check</button></form>
<form method="POST" action="{{ route('network-inventory.olt.cli-check',$device) }}">@csrf<button class="btn btn-outline-primary w-100" {{ !$device->ssh_enabled ? 'disabled' : '' }}>Test SSH CLI Connection</button></form>
<small class="d-block text-muted mt-2">CLI test runs only the read-only <code>show version</code> command. It does not provision or reboot an ONU.</small>
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Hardware</strong></div><div class="card-body">
<div class="d-flex justify-content-between"><span>PON Ports</span><strong>{{ $device->pon_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>GE Ports</span><strong>{{ $device->ge_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>SFP Ports</span><strong>{{ $device->sfp_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>SFP+ Ports</span><strong>{{ $device->sfp_plus_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>Firmware</span><strong>{{ $device->firmware ?: '—' }}</strong></div><div class="d-flex justify-content-between"><span>Serial</span><strong>{{ $device->serial_no ?: '—' }}</strong></div>
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Monitoring</strong></div><div class="card-body">
<div class="d-flex justify-content-between"><span>Monitor</span><strong>{{ ($device->monitor_enabled ?? false) ? 'Enabled' : 'Disabled' }}</strong></div><div class="d-flex justify-content-between"><span>Health Port</span><strong>{{ $device->health_port ?: '—' }}</strong></div><div class="d-flex justify-content-between"><span>Last Latency</span><strong>{{ $device->last_latency_ms !== null ? $device->last_latency_ms.' ms' : '—' }}</strong></div><div class="d-flex justify-content-between"><span>Last SNMP Check</span><strong>{{ optional($device->last_checked_at)->format('d M Y H:i:s') ?: 'Never' }}</strong></div>
<div class="d-flex justify-content-between mt-2"><span>SSH CLI Test</span><strong class="{{ $lastCliCheck?->status==='success' ? 'text-success' : ($lastCliCheck?->status==='failed' ? 'text-danger' : 'text-muted') }}">{{ $lastCliCheck ? strtoupper($lastCliCheck->status) : 'NOT TESTED' }}</strong></div>
<div class="d-flex justify-content-between"><span>Last CLI Test</span><strong>{{ $lastCliCheck?->created_at?->format('d M Y H:i:s') ?: 'Never' }}</strong></div>
@if($lastCliCheck?->status==='failed' && $lastCliCheck->error)<small class="d-block text-danger mt-1">{{ \Illuminate\Support\Str::limit($lastCliCheck->error,180) }}</small>@endif
<div class="mt-3"><span class="text-muted d-block">Diagnostic Command</span><code>{{ $device->diagnostic_command ?: (strtoupper((string)$device->vendor)==='VSOL' ? 'show onu opm-diag all' : 'Not configured') }}</code><small class="d-block text-muted mt-1">{{ strtoupper((string)$device->vendor)==='VSOL' ? 'PON-specific: show onu opm-diag pon &lt;PON&gt;' : 'Configure a vendor-specific read-only OPM command when supported.' }}</small></div>
</div></div></div>
</div>

<div class="card shadow-sm mt-3">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div><strong>Last Live SNMP / Interface Snapshot</strong><small class="d-block text-muted">Saved read-only poll; no SNMP write or SET operation</small></div>
    <div class="d-flex gap-2"><span class="badge {{ $liveMetricsSnapshot?->poll_status==='success' ? 'bg-success' : 'bg-secondary' }}">{{ $liveMetricsSnapshot ? ucfirst($liveMetricsSnapshot->poll_status) : 'Not polled' }}</span><form method="POST" action="{{ route('network-inventory.olt.diagnostics.metrics',$device) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Sync Live Metrics</button></form></div>
  </div>
  <div class="card-body">
    @if($liveMetricsSnapshot)
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><small class="text-muted d-block">Last poll</small><strong>{{ $liveMetricsSnapshot->sampled_at->format('d M Y H:i:s') }}</strong><small class="d-block text-muted">{{ $liveMetricsSnapshot->sampled_at->diffForHumans() }}</small></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><small class="text-muted d-block">ONU status</small><strong>{{ $liveMetricsSnapshot->onu_online }} online / {{ $liveMetricsSnapshot->onu_total }} total</strong><small class="d-block text-muted">{{ $liveMetricsSnapshot->onu_offline }} offline · {{ $liveMetricsSnapshot->onu_unknown }} unknown</small></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><small class="text-muted d-block">PON availability</small><strong>{{ $liveMetricsSnapshot->pon_up }} up / {{ $liveMetricsSnapshot->pon_up+$liveMetricsSnapshot->pon_down }} known</strong><small class="d-block text-muted">{{ $liveMetricsSnapshot->pon_down }} down · {{ $liveMetricsSnapshot->interface_count }} interfaces</small></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><small class="text-muted d-block">Mapped customers</small><strong>{{ number_format($liveMetricsSnapshot->mapped_customers) }}</strong><small class="d-block text-muted">Poll duration {{ number_format($liveMetricsSnapshot->poll_duration_ms) }} ms</small></div></div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-3"><small class="text-muted d-block">CPU</small><strong>{{ $liveMetricsSnapshot->cpu_percent!==null ? $liveMetricsSnapshot->cpu_percent.'%' : 'Unavailable' }}</strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Memory</small><strong>{{ $liveMetricsSnapshot->memory_percent!==null ? $liveMetricsSnapshot->memory_percent.'%' : 'Unavailable' }}</strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Chassis temperature</small><strong>{{ $liveMetricsSnapshot->chassis_temp_c!==null ? $liveMetricsSnapshot->chassis_temp_c.' °C' : 'Unavailable' }}</strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Uptime</small><strong>{{ data_get($liveMetricsSnapshot->metrics,'uptime_human') ?: 'Unavailable' }}</strong></div>
      </div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Interface</th><th>Role</th><th>State</th><th>ONU Online / Total</th><th>Avg RX</th><th>Avg TX</th><th>Avg Distance</th><th>Download / Upload</th></tr></thead><tbody>
      @forelse($liveMetricsSnapshot->ports as $port)<tr><td>{{ $port->interface_name }} <small class="text-muted">#{{ $port->if_index }}</small></td><td>{{ strtoupper($port->role) }}</td><td><span class="badge {{ $port->oper_status==='up'?'bg-success':($port->oper_status==='down'?'bg-danger':'bg-secondary') }}">{{ strtoupper($port->oper_status) }}</span></td><td>{{ $port->onu_online }} / {{ $port->onu_total }}</td><td>{{ $port->avg_rx_dbm!==null ? number_format((float)$port->avg_rx_dbm,2).' dBm' : '—' }}</td><td>{{ $port->avg_tx_dbm!==null ? number_format((float)$port->avg_tx_dbm,2).' dBm' : '—' }}</td><td>{{ $port->avg_distance_m!==null ? number_format((float)$port->avg_distance_m,0).' m' : '—' }}</td><td>{{ $port->in_bps!==null ? number_format((float)$port->in_bps/1000000,2) : '—' }} / {{ $port->out_bps!==null ? number_format((float)$port->out_bps/1000000,2) : '—' }} Mbps</td></tr>@empty<tr><td colspan="8" class="text-center text-muted">No interface rows in this snapshot.</td></tr>@endforelse
      </tbody></table></div>
    @else
      <div class="text-muted">No live metrics snapshot has been saved. Use <strong>Sync Live Metrics</strong> to perform an explicit read-only poll.</div>
    @endif
  </div>
</div>
<div class="card shadow-sm mt-3">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div><strong>ONU OPM Diag</strong><small class="d-block text-muted">Read-only saved optical diagnostics · {{ count($rows ?? []) }} ONU rows · fresh window {{ $opmFreshMinutes }} minutes</small></div>
    @php($opmStatus=$opm['meta']['opm_status'] ?? 'not_ready')
    <span class="badge {{ ($freshOpmCount ?? 0)>0 ? 'bg-success' : 'bg-warning text-dark' }}">Fresh OPM: {{ $freshOpmCount ?? 0 }} / {{ count($rows ?? []) }}</span>
  </div>
  <div class="card-body border-bottom">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label small mb-1">MAC</label><input class="form-control form-control-sm" name="opm_q" value="{{ $q ?? '' }}" placeholder="HH:HH:HH:HH:HH:HH"></div>
      <div class="col-md-3"><label class="form-label small mb-1">Port ID / PON</label><select class="form-select form-select-sm" name="opm_pon"><option value="">All PON</option>@foreach(($ponOptions ?? []) as $p)<option value="{{ $p }}" @selected((string)($pon ?? '')===(string)$p)>PON{{ $p }}</option>@endforeach</select></div>
      <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
      <div class="col-md-2"><a class="btn btn-sm btn-outline-secondary w-100" href="{{ route('network-inventory.olt.diagnostics',$device) }}">Reset</a></div>
    </form>
    @if(($opmStatus ?? '') !== 'ok')<div class="alert alert-warning py-2 mt-3 mb-0"><strong>Automatic OPM population:</strong> {{ $opm['meta']['opm_message'] ?? 'OPM SNMP is not ready.' }}</div>@endif
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0 text-nowrap">
      <thead><tr><th>ONU ID</th><th>ONU MAC</th><th>TX Power(dBm)</th><th>RX Power(dBm)</th><th>Measurement</th></tr></thead>
      <tbody>
      @forelse(($rows ?? []) as $row)
        @php($tx=$row['tx_power'] ?? null)
        @php($rx=$row['rx_power'] ?? null)
        <tr>
          <td><strong>{{ $row['onu_id'] ?? '—' }}</strong><small class="d-block text-muted">EPON0/{{ $row['pon_port'] ?? '—' }}:{{ $row['onu_id'] ?? '—' }}</small></td>
          <td class="font-monospace">{{ $row['onu_mac'] ?? '—' }}</td>
          <td>{{ is_numeric($tx) ? number_format((float)$tx,2) : '—' }}</td>
          <td>{{ is_numeric($rx) ? number_format((float)$rx,2) : '—' }}</td>
          <td>@if(!empty($row['opm_fresh']) && (is_numeric($tx)||is_numeric($rx)))<span class="badge bg-success">Fresh</span><small class="d-block text-muted">{{ \Carbon\Carbon::parse($row['opm_last_seen_at'])->diffForHumans() }}</small>@else<span class="badge bg-secondary">Stale / unavailable</span>@endif</td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-center py-4 text-muted">No fresh ONU MAC / TX / RX telemetry returned.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">OPM values are saved samples and are shown only when their measurement timestamp is within {{ $opmFreshMinutes }} minutes. Stale historical values are hidden. The OLT is not polled automatically by page rendering.</div>
</div>
</div>
</x-app-layout>
