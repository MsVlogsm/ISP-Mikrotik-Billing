<x-app-layout>
@push('styles')<link rel="stylesheet" href="{{ asset('xlink-olt/olt-source.css') }}">@endpush
<div class="container-fluid px-1">
<div class="d-flex justify-content-between align-items-center mb-3"><div><span class="text-uppercase small text-muted fw-semibold">Admin Panel</span><h3 class="mb-0">OLT Diagnostics</h3><small class="text-muted">Health and configuration diagnostics for {{ $device->name }}</small></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt.edit',$device) }}">Edit OLT</a><a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt') }}">Back</a></div></div>
@if(session('diagnostic_message'))<div class="alert {{ str_starts_with(session('diagnostic_message'),'Connectivity check passed') ? 'alert-success' : (str_starts_with(session('diagnostic_message'),'Connectivity check not ready') ? 'alert-warning' : 'alert-danger') }}">{{ session('diagnostic_message') }}</div>@endif
<div class="row g-3">
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Connection</strong></div><div class="card-body">
<p class="mb-2"><span class="text-muted">Host:</span> <strong>{{ $device->host ?: $device->ip_address ?: '—' }}</strong></p>
<p class="mb-2"><span class="text-muted">SNMP UDP Port:</span> <strong>{{ $device->snmp_port ?: 161 }}</strong></p>
<p class="mb-2"><span class="text-muted">Web:</span> <strong>{{ $device->web_protocol ?: '—' }}{{ $device->web_port ? ':'.$device->web_port : '' }}</strong></p>
<p class="mb-3"><span class="text-muted">Status:</span> <span class="badge {{ $device->status==='online'?'bg-success':($device->status==='offline'?'bg-danger':'bg-warning text-dark') }}">{{ ucfirst($device->status ?: 'unknown') }}</span></p>
<form method="POST" action="{{ route('network-inventory.olt.diagnostic-check',$device) }}">@csrf<button class="btn btn-primary w-100">Run Connectivity Check</button></form>
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Hardware</strong></div><div class="card-body">
<div class="d-flex justify-content-between"><span>PON Ports</span><strong>{{ $device->pon_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>GE Ports</span><strong>{{ $device->ge_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>SFP Ports</span><strong>{{ $device->sfp_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>SFP+ Ports</span><strong>{{ $device->sfp_plus_ports ?? '—' }}</strong></div><div class="d-flex justify-content-between"><span>Firmware</span><strong>{{ $device->firmware ?: '—' }}</strong></div><div class="d-flex justify-content-between"><span>Serial</span><strong>{{ $device->serial_no ?: '—' }}</strong></div>
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>Monitoring</strong></div><div class="card-body">
<div class="d-flex justify-content-between"><span>Monitor</span><strong>{{ ($device->monitor_enabled ?? false) ? 'Enabled' : 'Disabled' }}</strong></div><div class="d-flex justify-content-between"><span>Health Port</span><strong>{{ $device->health_port ?: '—' }}</strong></div><div class="d-flex justify-content-between"><span>Last Latency</span><strong>{{ $device->last_latency_ms !== null ? $device->last_latency_ms.' ms' : '—' }}</strong></div><div class="d-flex justify-content-between"><span>Last Check</span><strong>{{ optional($device->last_checked_at)->format('d M Y H:i:s') ?: 'Never' }}</strong></div><div class="mt-3"><span class="text-muted d-block">Diagnostic Command</span><code>{{ $device->diagnostic_command ?: (strtoupper((string)$device->vendor)==='VSOL' ? 'show onu opm-diag all' : 'Not configured') }}</code><small class="d-block text-muted mt-1">{{ strtoupper((string)$device->vendor)==='VSOL' ? 'PON-specific: show onu opm-diag pon &lt;PON&gt;' : 'Configure a vendor-specific read-only OPM command when supported.' }}</small></div>
</div></div></div>
</div>

<div class="card shadow-sm mt-3">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div><strong>ONU OPM Diag</strong><small class="d-block text-muted">Read-only optical diagnostics · {{ count($rows ?? []) }} ONU rows</small></div>
    @php($opmStatus=$opm['meta']['opm_status'] ?? 'not_ready')
    <span class="badge {{ $opmStatus === 'ok' ? 'bg-success' : 'bg-warning text-dark' }}">OPM SNMP: {{ strtoupper($opmStatus) }}</span>
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
      <thead><tr><th>ONU ID</th><th>ONU MAC</th><th>TX Power(dBm)</th><th>RX Power(dBm)</th></tr></thead>
      <tbody>
      @forelse(($rows ?? []) as $row)
        @php($tx=$row['tx_power'] ?? null)
        @php($rx=$row['rx_power'] ?? null)
        <tr>
          <td><strong>{{ $row['onu_id'] ?? '—' }}</strong><small class="d-block text-muted">EPON0/{{ $row['pon_port'] ?? '—' }}:{{ $row['onu_id'] ?? '—' }}</small></td>
          <td class="font-monospace">{{ $row['onu_mac'] ?? '—' }}</td>
          <td>{{ is_numeric($tx) ? number_format((float)$tx,2) : '—' }}</td>
          <td>{{ is_numeric($rx) ? number_format((float)$rx,2) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="text-center py-4 text-muted">No ONU MAC / TX / RX telemetry returned.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">OPM is read-only. Unsupported vendor metrics are shown as — rather than fabricated.</div>
</div>
</div>
</x-app-layout>
