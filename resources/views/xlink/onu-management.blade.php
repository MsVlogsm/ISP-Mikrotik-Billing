<x-app-layout>
@push('styles')
<style>
:root{--ir-bg:#e2e9f0;--ir-panel:#fff;--ir-ink:#061223;--ir-muted:#909dab;--ir-brand:#29313d;--ir-accent:#55a9ff;--ir-line:#dce4ed;--ir-radius:12px}
.onu-page{color:var(--ir-ink);font-family:"Instrument Sans",system-ui,sans-serif}
.onu-page .onu-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.onu-page .onu-eyebrow{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#697789}
.onu-page h2{font-size:clamp(23px,3vw,30px);font-weight:800;letter-spacing:-.035em;margin:3px 0 5px;color:var(--ir-ink)}
.onu-page .onu-subtitle{font-size:13px;color:#64748b;margin:0}
.onu-page .onu-actions{display:flex;flex-wrap:wrap;gap:8px}
.onu-page .onu-actions .btn{font-size:13px;font-weight:650;border-radius:8px;padding:9px 12px}
.onu-page .onu-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
.onu-page .onu-kpi{min-width:0;background:var(--ir-panel);border:1px solid var(--ir-line);border-radius:var(--ir-radius);padding:16px;box-shadow:0 2px 8px #06122308}
.onu-page .onu-kpi-label{font-size:12px;color:#697789;font-weight:600;display:flex;gap:8px;align-items:center}
.onu-page .onu-kpi-value{font-size:28px;line-height:1.15;font-weight:800;letter-spacing:-.04em;margin-top:10px}
.onu-page .onu-kpi-note{font-size:11px;color:#8491a0;margin-top:6px}
.onu-page .onu-panel{border:1px solid var(--ir-line);border-radius:var(--ir-radius);background:#fff;box-shadow:0 2px 8px #06122308;overflow:hidden}
.onu-page .onu-panel-head{padding:15px 18px;border-bottom:1px solid var(--ir-line);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.onu-page .onu-panel-head h3{font-size:15px;font-weight:800;margin:0}
.onu-page .onu-filter{padding:14px 18px;border-bottom:1px solid var(--ir-line);background:#f9fbfd}
.onu-page .form-control,.onu-page .form-select{font-size:13px;border-color:#d7e0ea;border-radius:8px;min-height:38px}
.onu-page .onu-table-wrap{overflow:auto}
.onu-page .onu-table{font-size:12px;white-space:nowrap;margin:0}
.onu-page .onu-table thead th{background:#f2f6fa;color:#667588;text-transform:uppercase;letter-spacing:.06em;font-size:10px;font-weight:800;padding:12px 13px;border-bottom:1px solid var(--ir-line)}
.onu-page .onu-table tbody td{padding:12px 13px;vertical-align:middle;border-color:#edf1f5}
.onu-page .onu-primary{font-weight:750;color:#152235}
.onu-page .onu-secondary{font-size:11px;color:#7b8998;margin-top:3px}
.onu-page .onu-status{display:inline-flex;align-items:center;gap:6px;border-radius:99px;padding:5px 9px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.onu-page .onu-status.online{color:#067647;background:#e7f8ef}.onu-page .onu-status.offline{color:#b42318;background:#fff0ee}.onu-page .onu-status.unknown{color:#8a5b00;background:#fff7dd}
.onu-page .onu-signal{font-weight:800}.onu-page .onu-signal.good{color:#087443}.onu-page .onu-signal.warning{color:#b77900}.onu-page .onu-signal.critical{color:#c43225}.onu-page .onu-signal.na{color:#8793a2;font-weight:500}
.onu-page .onu-empty{padding:46px 16px;text-align:center;color:#7b8998}.onu-page .onu-empty i{font-size:27px;display:block;margin-bottom:10px}
.onu-page .onu-foot{padding:12px 18px;border-top:1px solid var(--ir-line);font-size:11px;color:#8491a0;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
@media(max-width:1100px){.onu-page .onu-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:650px){.onu-page .onu-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.onu-page .onu-kpi{padding:12px}.onu-page .onu-kpi-value{font-size:24px}.onu-page .onu-head{margin-bottom:15px}.onu-page .onu-actions{width:100%}.onu-page .onu-actions .btn{flex:1}}
</style>
@endpush
<div class="container-fluid px-1 onu-page">
  <div class="onu-head">
    <div>
      <div class="onu-eyebrow">FTTH Network · Subscriber Operations</div>
      <h2>ONU Management</h2>
      <p class="onu-subtitle">ONU/customer mapping ledger and stored optical telemetry. Missing readings are shown as unavailable, never estimated.</p>
    </div>
    <div class="onu-actions">
      <form method="POST" action="{{ route('network-inventory.olt.sync') }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.innerText='Syncing…';">@csrf<button class="btn btn-dark" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Sync ONU Inventory</button></form>
      <a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt') }}"><i class="bi bi-hdd-network me-1"></i>OLT Devices</a>
      @if(auth()->user()?->hasRole('Super Admin'))<a class="btn btn-outline-info" href="{{ route('network-inventory.onu-mac-ledger') }}"><i class="bi bi-journal-text me-1"></i>MAC Ledger</a>@endif
      <a class="btn btn-outline-primary" href="{{ route('network-inventory.onu-management.export') }}"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>
  <div class="onu-kpis">
    <div class="onu-kpi"><div class="onu-kpi-label"><i class="bi bi-diagram-3"></i>Total ONU records</div><div class="onu-kpi-value">{{ number_format($stats['total']) }}</div><div class="onu-kpi-note">Saved mapping records</div></div>
    <div class="onu-kpi"><div class="onu-kpi-label"><i class="bi bi-wifi"></i>Online</div><div class="onu-kpi-value" style="color:#07834d">{{ number_format($stats['online']) }}</div><div class="onu-kpi-note">Latest stored status</div></div>
    <div class="onu-kpi"><div class="onu-kpi-label"><i class="bi bi-wifi-off"></i>Offline</div><div class="onu-kpi-value" style="color:#c43225">{{ number_format($stats['offline']) }}</div><div class="onu-kpi-note">Latest stored status</div></div>
    <div class="onu-kpi"><div class="onu-kpi-label"><i class="bi bi-activity"></i>Low signal</div><div class="onu-kpi-value" style="color:#b77900">{{ number_format($stats['warning']) }}</div><div class="onu-kpi-note">Stored RX below −20 dBm</div></div>
    <div class="onu-kpi"><div class="onu-kpi-label"><i class="bi bi-person-check"></i>Mapped customers</div><div class="onu-kpi-value">{{ number_format($stats['mapped']) }}</div><div class="onu-kpi-note">Customer-linked records</div></div>
  </div>
  @if(session('onu_sync_message'))<div class="alert alert-{{ session('onu_sync_success') ? 'success' : 'warning' }} py-2"><strong>Sync result:</strong> {{ session('onu_sync_message') }}</div>@endif
  @if(session('inventory_message'))<div class="alert alert-info py-2">{{ session('inventory_message') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif
  <section class="onu-panel">
    <div class="onu-panel-head"><h3><i class="bi bi-list-ul me-2"></i>ONU / Customer Register</h3><span class="small text-secondary">{{ $mappings->total() }} matching records</span></div>
    <div class="onu-filter">
      <form method="GET" action="{{ route('network-inventory.onu-management') }}" class="row g-2 align-items-end">
        <div class="col-12 col-lg-4"><label class="form-label small fw-semibold mb-1">Search</label><input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Customer ID, name, PPPoE, MAC, serial, PON…"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-semibold mb-1">OLT / Zone</label><select class="form-select" name="olt"><option value="">All OLTs</option>@foreach($olts as $olt)<option value="{{ $olt->id }}" @selected((string)$oltId===(string)$olt->id)>{{ $olt->name }}{{ $olt->location ? ' · '.$olt->location : '' }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-2"><label class="form-label small fw-semibold mb-1">Status</label><select class="form-select" name="status"><option value="">All statuses</option><option value="online" @selected($status==='online')>Online</option><option value="offline" @selected($status==='offline')>Offline</option><option value="unknown" @selected($status==='unknown')>Unknown</option></select></div>
        <div class="col-6 col-lg-2"><label class="form-label small fw-semibold mb-1">Signal quality</label><select class="form-select" name="signal"><option value="">All readings</option><option value="good" @selected($signal==='good')>Good (≥ −20)</option><option value="warning" @selected($signal==='warning')>Warning (−27 to −20)</option><option value="critical" @selected($signal==='critical')>Critical (&lt; −27)</option></select></div>
        <div class="col-6 col-lg-1 d-flex gap-1"><button class="btn btn-primary flex-grow-1" type="submit" aria-label="Apply filters"><i class="bi bi-search"></i></button><a class="btn btn-outline-secondary" href="{{ route('network-inventory.onu-management') }}" aria-label="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></a></div>
      </form>
    </div>
    <div class="onu-table-wrap">
      <table class="table onu-table">
        <thead><tr><th>Customer</th><th>OLT / PON</th><th>ONU ID / Model</th><th>MAC / Serial</th><th>RX / TX Power</th><th>Status</th><th>Last Seen</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($mappings as $m)
          @php($opmFresh=$m->opm_last_seen_at !== null)
          @php($rx=$opmFresh && is_numeric($m->rx_power) ? (float)$m->rx_power : null)
          @php($tx=$opmFresh && is_numeric($m->tx_power) ? (float)$m->tx_power : null)
          @php($signalClass=$rx===null?'na':($rx>=-20?'good':($rx>=-27?'warning':'critical')))
          <tr>
            <td><div class="onu-primary">{{ $m->customer?->customer_name ?: 'Unmapped customer' }}</div><div class="onu-secondary">{{ $m->customer?->customer_unique_id ?: 'No customer ID' }}</div><div class="onu-secondary">PPPoE: {{ $m->pppUser?->username ?: '—' }}</div></td>
            <td><div class="onu-primary">{{ $m->olt?->name ?: 'OLT unavailable' }}</div><div class="onu-secondary">PON {{ $m->pon_port ?: '—' }}</div></td>
            <td><div class="onu-primary">{{ $m->onu_id ?: '—' }}</div><div class="onu-secondary">{{ $m->onu_type ?: 'Model unavailable' }}</div></td>
            <td><div>{{ $m->onu_mac ?: '—' }}</div><div class="onu-secondary">{{ $m->onu_serial ?: 'Serial unavailable' }}</div></td>
            <td><div class="onu-signal {{ $signalClass }}">RX {{ $rx!==null ? number_format($rx,2).' dBm' : '—' }}</div><div class="onu-secondary">TX {{ $tx!==null ? number_format($tx,2).' dBm' : '—' }}</div><div class="onu-secondary">OPM: {{ $m->opm_last_seen_at ? (($m->opm_source==='operator-supplied-olt-cli' ? 'CLI sample · ' : '').$m->opm_last_seen_at->format('d M Y H:i:s')) : (($m->rx_power !== null || $m->tx_power !== null) ? 'stale value hidden · no timestamp' : 'no reading') }}</div></td>
            <td><span class="onu-status {{ in_array($m->status,['online','offline'],true) ? $m->status : 'unknown' }}"><i class="bi {{ $m->status==='online'?'bi-check-circle-fill':($m->status==='offline'?'bi-x-circle-fill':'bi-question-circle-fill') }}"></i>{{ ucfirst($m->status ?: 'unknown') }}</span></td>
            <td>{{ $m->last_seen_at ? $m->last_seen_at->format('d M Y') : '—' }}<div class="onu-secondary">{{ $m->last_seen_at ? $m->last_seen_at->format('H:i:s') : 'No successful sync recorded' }}</div></td>
            <td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-primary" href="{{ $m->olt ? route('network-inventory.olt.customers',$m->olt) : route('network-inventory.onu-management') }}" title="Open OLT/customer mapping"><i class="bi bi-link-45deg"></i></a><a class="btn btn-sm btn-outline-secondary" href="{{ $m->olt ? route('network-inventory.olt.diagnostics',$m->olt) : route('network-inventory.onu-management') }}" title="Read-only OLT diagnostics"><i class="bi bi-activity"></i></a></div></td>
          </tr>
        @empty
          <tr><td colspan="8"><div class="onu-empty"><i class="bi bi-diagram-3"></i><strong>No saved ONU mappings found</strong><div class="mt-1">Run Sync ONU Inventory from an OLT that is reachable and configured, or open OLT Devices to inspect connectivity.</div><a class="btn btn-sm btn-outline-primary mt-3" href="{{ route('network-inventory.olt') }}">Open OLT Devices</a></div></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="onu-foot"><span>Displaying stored records from X-Link network inventory.</span><span>Page {{ $mappings->currentPage() }} of {{ max(1,$mappings->lastPage()) }}</span></div>
    @if($mappings->hasPages())<div class="p-3">{{ $mappings->links() }}</div>@endif
  </section>
</div>
</x-app-layout>