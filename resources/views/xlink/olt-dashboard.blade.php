<x-app-layout>
@push('styles')
<link rel="stylesheet" href="{{ asset('xlink-olt/olt-dashboard.css') }}?v={{ filemtime(public_path('xlink-olt/olt-dashboard.css')) }}">
@endpush

<div class="container-fluid olt-dash-page px-2 px-xl-3">
    <div class="olt-dash-topbar">
        <div>
            <div class="olt-dash-breadcrumb"><a href="{{ route('network-inventory') }}">Network Inventory</a><i class="bi bi-chevron-right"></i><a href="{{ route('network-inventory.olt') }}">OLT Management</a><i class="bi bi-chevron-right"></i><span>{{ $device->name }}</span></div>
            <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                <span class="olt-dash-live-dot {{ $device->status === 'online' ? 'is-online' : ($device->status === 'offline' ? 'is-offline' : 'is-unknown') }}"></span>
                <h1 class="olt-dash-title mb-0">{{ $device->name }} · OLT Overview</h1>
                <span class="olt-dash-state {{ $device->status === 'online' ? 'online' : ($device->status === 'offline' ? 'offline' : 'unknown') }}">{{ ucfirst($device->status ?: 'unknown') }}</span>
            </div>
            <div class="olt-dash-subtitle">{{ $deviceInfo['vendor'] ?: 'OLT' }} {{ $deviceInfo['model'] ? '· '.$deviceInfo['model'] : '' }} {{ $deviceInfo['ip'] ? '· '.$deviceInfo['ip'] : '' }}</div>
        </div>
        <div class="olt-dash-actions">
            <a class="btn btn-light" href="{{ route('network-inventory.olt') }}"><i class="bi bi-arrow-left"></i><span>OLT List</span></a>
            <a class="btn btn-outline-primary" href="{{ route('network-inventory.olt.diagnostics',$device) }}"><i class="bi bi-activity"></i><span>Diagnostics</span></a>
            <form method="POST" action="{{ route('network-inventory.olt.diagnostic-check',$device) }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.querySelector('span').textContent='Checking…';">
                @csrf
                <button class="btn btn-outline-success" type="submit"><i class="bi bi-heart-pulse"></i><span>Poll Status</span></button>
            </form>
            <form method="POST" action="{{ route('network-inventory.olt.dashboard.metrics',$device) }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.querySelector('span').textContent='Polling…';">
                @csrf
                <button class="btn btn-outline-info" type="submit"><i class="bi bi-activity"></i><span>Sync Live Metrics</span></button>
            </form>
            <form method="POST" action="{{ route('network-inventory.olt.dashboard.sync',$device) }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.querySelector('span').textContent='Syncing…';">
                @csrf
                <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat"></i><span>Sync ONU</span></button>
            </form>
        </div>
    </div>

    @if(session('olt_sync_message'))
        <div class="alert alert-{{ session('olt_sync_success') ? 'success' : 'warning' }} olt-dash-flash">
            <strong>Read-only ONU sync</strong>
            <pre class="mb-0 mt-1 text-wrap">{{ session('olt_sync_message') }}</pre>
        </div>
    @endif
    @if(session('olt_metrics_message'))
        <div class="alert alert-{{ session('olt_metrics_success') ? 'success' : 'warning' }} olt-dash-flash">
            <strong>Live Metrics Poll</strong><pre class="mb-0 mt-1 text-wrap">{{ session('olt_metrics_message') }}</pre>
        </div>
    @endif
    @if(session('diagnostic_message'))
        <div class="alert alert-info olt-dash-flash"><strong>OLT Status Poll</strong><div>{{ session('diagnostic_message') }}</div></div>
    @endif

    <section class="olt-dash-hero">
        <div class="olt-dash-hero-main">
            <div class="olt-dash-hero-kicker"><i class="bi bi-hdd-network"></i> DEVICE OVERVIEW</div>
            <h2>{{ $device->name }}</h2>
            <div class="olt-dash-meta">
                <span><i class="bi bi-geo-alt"></i>{{ $deviceInfo['location'] ?: 'Location not set' }}</span>
                <span><i class="bi bi-hdd"></i>{{ $deviceInfo['model'] ?: 'Model not recorded' }}</span>
                <span><i class="bi bi-diagram-3"></i>{{ $deviceInfo['olt_type'] ?: 'PON profile not set' }}</span>
            </div>
            <div class="olt-dash-hero-links">
                <a href="{{ route('network-inventory.olt.customers',$device) }}"><i class="bi bi-people"></i> ONU / Customer Mapping</a>
                <a href="{{ route('network-inventory.olt.provisioning',$device) }}"><i class="bi bi-terminal"></i> Provisioning</a>
                <a href="{{ route('network-inventory.onu-management',['olt'=>$device->id]) }}"><i class="bi bi-list-ul"></i> All ONU Inventory</a>
            </div>
        </div>
        <div class="olt-dash-hero-status">
            <div class="olt-dash-status-ring {{ $device->status === 'online' ? 'is-online' : ($device->status === 'offline' ? 'is-offline' : 'is-unknown') }}">
                <i class="bi {{ $device->status === 'online' ? 'bi-check2' : ($device->status === 'offline' ? 'bi-exclamation' : 'bi-hourglass') }}"></i>
            </div>
            <strong>{{ strtoupper($device->status ?: 'UNKNOWN') }}</strong>
            <small>Management status</small>
            <small>{{ $latestHealth?->checked_at?->diffForHumans() ?: 'No health sample recorded' }}</small>
        </div>
    </section>

    @php($liveUptime = $liveMetrics['uptime_human'] ?? null)
    @php($capacityTotal = $liveMetricSnapshot?->capacity_total)
    @php($capacityUsed = (int) ($liveMetricSnapshot?->mapped_customers ?? $stats['mapped_customers']))
    @php($ponTotal = $ponLivePorts->count())
    @php($ponUp = (int) ($liveMetricSnapshot?->pon_up ?? 0))
    @php($hardwareAvailable = collect($liveMetrics['hardware_metric_availability'] ?? [])->filter()->count())
    @php($hardwareConfigured = count($liveMetrics['hardware_metric_availability'] ?? []))
    @php($systemHealthLabel = !$liveMetricSnapshot ? 'Not polled' : ($device->status === 'online' ? ($hardwareAvailable === $hardwareConfigured && $hardwareConfigured > 0 ? 'Healthy' : 'Management Online · Partial telemetry') : 'Check required'))
    <section class="olt-live-kpis mb-3">
        <article class="olt-live-kpi"><span class="olt-live-kpi-icon"><i class="bi bi-clock-history"></i></span><div><small>Operational Uptime</small><strong>{{ $liveUptime ?: '—' }}</strong><em>{{ $liveMetricSnapshot ? 'sysUpTime · '.$liveMetricSnapshot->sampled_at->diffForHumans() : 'Run Sync Live Metrics' }}</em></div></article>
        <article class="olt-live-kpi"><span class="olt-live-kpi-icon"><i class="bi bi-people"></i></span><div><small>Customer Capacity</small><strong>{{ $capacityTotal ? number_format($capacityUsed).' / '.number_format($capacityTotal) : number_format($capacityUsed).' mapped' }}</strong><em>{{ $capacityTotal ? number_format(min(100,($capacityUsed/max(1,$capacityTotal))*100),1).'% of configured capacity' : 'Capacity profile not configured' }}</em></div></article>
        <article class="olt-live-kpi"><span class="olt-live-kpi-icon"><i class="bi bi-layers"></i></span><div><small>PON Port Availability</small><strong>{{ $ponTotal ? $ponUp.' / '.$ponTotal : '—' }}</strong><em>{{ $ponTotal ? ($ponLivePorts->where('oper_status','down')->count().' down · '.$ponLivePorts->where('oper_status','unknown')->count().' unknown') : 'No IF-MIB port snapshot' }}</em></div></article>
        <article class="olt-live-kpi"><span class="olt-live-kpi-icon"><i class="bi bi-heart-pulse"></i></span><div><small>System Health</small><strong class="{{ $device->status === 'online' ? 'text-success' : 'text-warning' }}">{{ $systemHealthLabel }}</strong><em>{{ $liveMetricSnapshot ? ($hardwareAvailable.'/'.$hardwareConfigured.' hardware probes available') : 'No live hardware poll recorded' }}</em></div></article>
    </section>

    <section class="olt-live-panel mb-3">
        <div class="olt-live-panel-heading"><div><span class="olt-live-icon"><i class="bi bi-cpu"></i></span><div><h3>Device Overview & Hardware Telemetry</h3><p>Live read-only system data · no SNMP SET commands</p></div></div><span class="olt-live-sample">{{ $liveMetricSnapshot ? 'Polled '.$liveMetricSnapshot->sampled_at->format('d M Y H:i:s') : 'Never polled' }}</span></div>
        <div class="olt-live-hardware-grid">
            <div><small>Vendor</small><strong>{{ $device->vendor ?: '—' }}</strong></div>
            <div><small>Device Model</small><strong>{{ $device->model ?: '—' }}</strong></div>
            <div><small>Hardware Version</small><strong>{{ $deviceInfo['hardware_version'] ?: 'Not reported' }}</strong></div>
            <div><small>Firmware</small><strong>{{ $device->firmware ?: 'Not recorded' }}</strong></div>
            <div><small>Serial Number</small><strong>{{ $device->serial_no ?: 'Not recorded' }}</strong></div>
            <div><small>Live sysDescr</small><strong>{{ $liveMetricSnapshot?->sys_descr ?: 'Not polled' }}</strong></div>
            <div><small>CPU Utilization</small><strong>{{ $liveMetricSnapshot?->cpu_percent !== null ? number_format((float)$liveMetricSnapshot->cpu_percent,1).'%' : 'Not exposed by SNMP' }}</strong></div>
            <div><small>Memory Utilization</small><strong>{{ $liveMetricSnapshot?->memory_percent !== null ? number_format((float)$liveMetricSnapshot->memory_percent,1).'%' : 'Not exposed by SNMP' }}</strong></div>
            <div><small>Chassis Temperature</small><strong>{{ $liveMetricSnapshot?->chassis_temp_c !== null ? number_format((float)$liveMetricSnapshot->chassis_temp_c,1).' °C' : 'Not exposed by SNMP' }}</strong></div>
            <div><small>Fan Status</small><strong>Not exposed by current profile</strong></div>
            <div><small>SNMP Endpoint</small><strong>{{ $deviceInfo['ip'] ?: '—' }}:{{ $deviceInfo['snmp_port'] }}</strong></div>
            <div><small>SNMP Version</small><strong>{{ strtoupper($deviceInfo['snmp_version']) }}</strong></div>
            <div><small>IF-MIB Interfaces</small><strong>{{ $liveMetricSnapshot ? $liveMetricSnapshot->interface_count : '—' }}</strong></div>
            <div><small>Uplink Availability</small><strong>{{ $liveMetricSnapshot ? $liveMetricSnapshot->uplink_up.' up / '.($liveMetricSnapshot->uplink_up+$liveMetricSnapshot->uplink_down).' known' : '—' }}</strong></div>
            <div><small>Last Poll Duration</small><strong>{{ $liveMetricSnapshot?->poll_duration_ms !== null ? number_format($liveMetricSnapshot->poll_duration_ms).' ms' : '—' }}</strong></div>
            <div><small>Telemetry Source</small><strong>{{ $liveMetrics['mib_profile'] ?? 'Read-only adapter' }}</strong></div>
        </div>
        @if($liveMetricSnapshot && !empty($liveMetrics['hardware_metric_note']))<div class="olt-live-note"><i class="bi bi-info-circle"></i>{{ $liveMetrics['hardware_metric_note'] }}</div>@endif
        @if(!$liveMetricSnapshot)<div class="olt-live-note"><i class="bi bi-info-circle"></i>Hardware and IF-MIB data are shown only after a successful live metrics poll. Use <strong>Sync Live Metrics</strong> above.</div>@endif
    </section>

    <section class="olt-live-panel mb-3">
        <div class="olt-live-panel-heading"><div><span class="olt-live-icon"><i class="bi bi-diagram-3"></i></span><div><h3>PON Ports & Optical Telemetry</h3><p>Per-port live state, ONU distribution and measured optical values</p></div></div><span class="olt-live-sample">{{ $ponTotal }} PON interfaces detected</span></div>
        <div class="table-responsive"><table class="table olt-live-table align-middle mb-0"><thead><tr><th>Port / ifIndex</th><th>Status</th><th>Temperature</th><th>Voltage</th><th>Bias</th><th>ONUs Online / Total</th><th>Avg RX</th><th>Avg TX</th><th>Distance</th><th>Download</th><th>Upload</th><th>Packet Health</th><th>Action</th></tr></thead><tbody>
        @forelse($ponLivePorts as $port)
            <tr><td><strong>{{ $port->interface_name }}</strong><small>ifIndex {{ $port->if_index }}</small></td><td><span class="olt-live-state {{ $port->oper_status }}">{{ strtoupper($port->oper_status) }}</span></td><td>—<small>Not exposed by current profile</small></td><td>—<small>Not exposed by current profile</small></td><td>—<small>Not exposed by current profile</small></td><td><strong>{{ $port->onu_online }} / {{ $port->onu_total }}</strong></td><td>{{ $port->avg_rx_dbm !== null ? number_format((float)$port->avg_rx_dbm,2).' dBm' : '—' }}<small>{{ $port->rx_sample_count }} samples</small></td><td>{{ $port->avg_tx_dbm !== null ? number_format((float)$port->avg_tx_dbm,2).' dBm' : '—' }}</td><td>{{ $port->avg_distance_m !== null ? number_format((float)$port->avg_distance_m,0).' m' : '—' }}</td><td>{{ $port->in_bps !== null ? number_format((float)$port->in_bps/1000000,2).' Mbps' : 'Await next sample' }}</td><td>{{ $port->out_bps !== null ? number_format((float)$port->out_bps/1000000,2).' Mbps' : 'Await next sample' }}</td><td>{{ $port->packet_health_percent !== null ? number_format((float)$port->packet_health_percent,3).'%' : 'Await next sample' }}</td><td><form method="POST" action="{{ route('network-inventory.olt.dashboard.metrics',$device) }}" onsubmit="const b=this.querySelector('button');b.disabled=true;b.textContent='Polling…';">@csrf<input type="hidden" name="focus_if_index" value="{{ $port->if_index }}"><button class="btn btn-sm btn-outline-info" type="submit" title="Refresh all OLT read-only metrics; includes this port"><i class="bi bi-arrow-repeat"></i> Poll</button></form></td></tr>
        @empty<tr><td colspan="13" class="text-center text-muted py-4">No live PON port data yet. Poll Live Metrics to discover IF-MIB port indexes.</td></tr>@endforelse
        </tbody></table></div>
        <div class="olt-live-note">Port temperature, voltage and bias are intentionally not displayed because the current V-SOL IF-MIB profile does not return verified per-port sensor values. ONU RX/TX averages use only fresh values from the same PON.</div>
    </section>

    <section class="olt-live-panel mb-3">
        <div class="olt-live-panel-heading"><div><span class="olt-live-icon"><i class="bi bi-arrow-left-right"></i></span><div><h3>Uplink Interface Telemetry</h3><p>IF-MIB counters and measured throughput between two successful polls</p></div></div><span class="olt-live-sample">{{ $uplinkLivePorts->count() }} uplink interfaces</span></div>
        <div class="table-responsive"><table class="table olt-live-table align-middle mb-0"><thead><tr><th>Interface</th><th>Role</th><th>State</th><th>Inbound</th><th>Outbound</th><th>In Counter</th><th>Out Counter</th></tr></thead><tbody>
        @forelse($uplinkLivePorts as $port)<tr><td><strong>{{ $port->interface_name }}</strong><small>ifIndex {{ $port->if_index }}</small></td><td>{{ strtoupper(str_replace('uplink_','',$port->role)) }}</td><td><span class="olt-live-state {{ $port->oper_status }}">{{ strtoupper($port->oper_status) }}</span></td><td>{{ $port->in_bps !== null ? number_format((float)$port->in_bps/1000000,2).' Mbps' : 'Waiting for next counter sample' }}</td><td>{{ $port->out_bps !== null ? number_format((float)$port->out_bps/1000000,2).' Mbps' : 'Waiting for next counter sample' }}</td><td>{{ $port->packet_health_percent !== null ? number_format((float)$port->packet_health_percent,3).'%' : 'Await next sample' }}</td><td>{{ $port->in_octets !== null ? number_format((float)$port->in_octets).' B' : '—' }}</td><td>{{ $port->out_octets !== null ? number_format((float)$port->out_octets).' B' : '—' }}</td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No verified uplink interfaces reported by IF-MIB.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="olt-dash-kpis">
        <article class="olt-dash-kpi">
            <div class="olt-dash-kpi-icon blue"><i class="bi bi-diagram-3"></i></div>
            <div class="olt-dash-kpi-label">Total Discovered ONU</div>
            <div class="olt-dash-kpi-value">{{ number_format((int)$stats['total']) }}</div>
            <div class="olt-dash-kpi-foot">OLT inventory</div>
        </article>
        <article class="olt-dash-kpi">
            <div class="olt-dash-kpi-icon green"><i class="bi bi-broadcast-pin"></i></div>
            <div class="olt-dash-kpi-label">Online ONU</div>
            <div class="olt-dash-kpi-value green-text">{{ number_format((int)$stats['online']) }}</div>
            <div class="olt-dash-kpi-foot">{{ $stats['total'] ? number_format(($stats['online'] / max(1,$stats['total'])) * 100,1).'%' : '—' }} of discovered ONU</div>
        </article>
        <article class="olt-dash-kpi">
            <div class="olt-dash-kpi-icon red"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="olt-dash-kpi-label">Offline / Unknown</div>
            <div class="olt-dash-kpi-value red-text">{{ number_format((int)$stats['offline'] + (int)$stats['unknown']) }}</div>
            <div class="olt-dash-kpi-foot">{{ number_format((int)$stats['offline']) }} offline · {{ number_format((int)$stats['unknown']) }} unknown</div>
        </article>
        <article class="olt-dash-kpi">
            <div class="olt-dash-kpi-icon violet"><i class="bi bi-person-check"></i></div>
            <div class="olt-dash-kpi-label">Mapped Customers</div>
            <div class="olt-dash-kpi-value">{{ number_format((int)$stats['mapped_customers']) }}</div>
            <div class="olt-dash-kpi-foot">{{ number_format((int)$stats['mapped_onus']) }} ONU mapped · {{ number_format(max(0,(int)$stats['total']-(int)$stats['mapped_onus'])) }} need mapping</div>
        </article>
        <article class="olt-dash-kpi">
            <div class="olt-dash-kpi-icon amber"><i class="bi bi-lightning-charge"></i></div>
            <div class="olt-dash-kpi-label">Average RX Power</div>
            <div class="olt-dash-kpi-value">@if($stats['avg_rx_power'] !== null){{ number_format((float)$stats['avg_rx_power'],2) }} <small>dBm</small>@else—@endif</div>
            <div class="olt-dash-kpi-foot">{{ number_format((int)$stats['rx_sample_count']) }} signal samples in latest verified poll</div>
        </article>
    </section>

    <section class="olt-dash-trends">
        @foreach($charts as $chart)
            <article class="olt-dash-chart-card">
                <div class="olt-dash-card-heading">
                    <div><h3>{{ $chart['title'] }}</h3><p>{{ $chart['subtitle'] }}</p></div>
                    @if(!$chart['empty'])
                        <strong>{{ number_format((float)$chart['latest'], $chart['unit'] === 'dBm' ? 2 : 0) }} <small>{{ $chart['unit'] }}</small></strong>
                    @else
                        <span class="olt-dash-muted-tag">No history</span>
                    @endif
                </div>
                @if(!$chart['empty'])
                    <svg class="olt-dash-sparkline" viewBox="0 0 320 104" role="img" aria-label="{{ $chart['title'] }}">
                        <line x1="10" y1="22" x2="310" y2="22"></line>
                        <line x1="10" y1="55" x2="310" y2="55"></line>
                        <line x1="10" y1="88" x2="310" y2="88"></line>
                        <polyline points="{{ $chart['points'] }}"></polyline>
                    </svg>
                    <div class="olt-dash-chart-foot"><span>{{ $chart['labels'][0] ?? '' }}</span><span>{{ $chart['labels'][count($chart['labels'])-1] ?? '' }}</span></div>
                @else
                    <div class="olt-dash-chart-empty"><i class="bi bi-graph-up"></i><span>Trend will appear after verified ONU syncs.</span></div>
                @endif
            </article>
        @endforeach
    </section>

    <section class="olt-dash-main-grid">
        @php($uplinkDownloadBps = $uplinkLivePorts->whereNotNull('in_bps')->sum('in_bps'))
        @php($uplinkUploadBps = $uplinkLivePorts->whereNotNull('out_bps')->sum('out_bps'))
        <article class="olt-dash-panel olt-dash-bandwidth-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon"><i class="bi bi-speedometer2"></i></span><div><h3>Uplink Bandwidth</h3><p>Measured IF-MIB counter delta across active uplink interfaces</p></div></div>
                <span class="olt-dash-pill {{ $uplinkLivePorts->isNotEmpty() ? 'success' : 'neutral' }}">{{ $uplinkLivePorts->count() }} interfaces</span>
            </div>
            @if($uplinkLivePorts->whereNotNull('in_bps')->isNotEmpty() || $uplinkLivePorts->whereNotNull('out_bps')->isNotEmpty())
                <div class="olt-live-bandwidth-totals"><div><small>Download / Inbound</small><strong>{{ number_format((float)$uplinkDownloadBps/1000000,2) }} Mbps</strong></div><div><small>Upload / Outbound</small><strong>{{ number_format((float)$uplinkUploadBps/1000000,2) }} Mbps</strong></div></div>
                <div class="olt-live-uplink-list">@foreach($uplinkLivePorts as $port)<div><span><i class="olt-live-state-dot {{ $port->oper_status }}"></i><strong>{{ $port->interface_name }}</strong></span><span>↓ {{ $port->in_bps !== null ? number_format((float)$port->in_bps/1000000,2).' Mbps' : '—' }} <b>↑ {{ $port->out_bps !== null ? number_format((float)$port->out_bps/1000000,2).' Mbps' : '—' }}</b></span></div>@endforeach</div>
            @else
                <div class="olt-dash-bandwidth-empty"><div class="olt-dash-bandwidth-art"><i class="bi bi-activity"></i></div><strong>Counter baseline recorded; waiting for the next successful poll</strong><p>Throughput is calculated from IF-MIB Counter64/Counter32 deltas over the elapsed time. No rate is estimated from cumulative byte totals.</p></div>
            @endif
        </article>

        <article class="olt-dash-panel olt-dash-signal-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon"><i class="bi bi-bullseye"></i></span><div><h3>ONU Signal Overview</h3><p>Latest timestamped RX measurements</p></div></div>
                <span class="olt-dash-pill {{ $signalTotal ? 'success' : 'neutral' }}">{{ $signalTotal ? 'OPM available' : 'No fresh samples' }}</span>
            </div>
            <div class="olt-dash-signal-content">
                <div class="olt-dash-donut-wrap">
                    <div class="olt-dash-donut" style="--good:{{ $signalPercentages['good'] }}%;--warning-end:{{ $signalPercentages['good'] + $signalPercentages['warning'] }}%;--has-signal:{{ $signalTotal > 0 ? 1 : 0 }};">
                        <div><strong>{{ number_format($signalTotal) }}</strong><small>RX samples</small></div>
                    </div>
                </div>
                <div class="olt-dash-signal-legend">
                    <div><i class="good"></i><span>Good <small>≥ −20 dBm</small></span><strong>{{ number_format((int)$stats['signal_good']) }}</strong></div>
                    <div><i class="warning"></i><span>Warning <small>−27 to −20 dBm</small></span><strong>{{ number_format((int)$stats['signal_warning']) }}</strong></div>
                    <div><i class="critical"></i><span>Critical <small>&lt; −27 dBm</small></span><strong>{{ number_format((int)$stats['signal_critical']) }}</strong></div>
                    <div><i class="nosample"></i><span>No RX sample</span><strong>{{ number_format((int)$stats['signal_no_sample']) }}</strong></div>
                </div>
            </div>
            <div class="olt-dash-signal-note">
                @if($latestSnapshot)
                    Snapshot {{ $latestSnapshot->sampled_at->format('d M Y H:i:s') }} · average RX {{ $stats['avg_rx_power'] !== null ? number_format((float)$stats['avg_rx_power'],2).' dBm' : 'unavailable' }}
                @else
                    Signal classification starts after the first complete, verified read-only ONU sync.
                @endif
            </div>
        </article>
    </section>

    <section class="olt-dash-main-grid">
        <article class="olt-dash-panel olt-dash-pon-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon"><i class="bi bi-diagram-2"></i></span><div><h3>PON Ports & ONU Distribution</h3><p>Saved ONU-to-PON assignments</p></div></div>
                <span class="olt-dash-pill neutral">{{ $ponDistribution->count() }} PON entries</span>
            </div>
            @if($ponDistribution->isNotEmpty())
                <div class="olt-dash-pon-list">
                    @foreach($ponDistribution as $port)
                        <div class="olt-dash-pon-row">
                            <div class="olt-dash-pon-label"><strong>PON {{ $port->pon_port }}</strong><span>{{ number_format((int)$port->online) }} online / {{ number_format((int)$port->total) }} known</span></div>
                            <div class="olt-dash-progress"><span style="width:{{ $port->total ? min(100,((int)$port->online/(int)$port->total)*100) : 0 }}%"></span></div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="olt-dash-empty"><i class="bi bi-diagram-2"></i><span>No PON port assignments recorded yet.</span></div>
            @endif
            <div class="olt-dash-panel-foot">Configured PON capacity: {{ $device->pon_ports !== null ? number_format((int)$device->pon_ports).' ports' : 'not recorded' }}. This is separate from discovered ONU count.</div>
        </article>

        <article class="olt-dash-panel olt-dash-alert-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon alert"><i class="bi bi-bell"></i></span><div><h3>System Alerts</h3><p>Current device and ONU observations</p></div></div>
                <span class="olt-dash-pill {{ collect($alerts)->contains(fn($alert)=>$alert['level']==='critical') ? 'danger' : (count($alerts) ? 'warning' : 'success') }}">{{ count($alerts) }} {{ count($alerts) === 1 ? 'alert' : 'alerts' }}</span>
            </div>
            <div class="olt-dash-alerts">
                @forelse($alerts as $alert)
                    <div class="olt-dash-alert {{ $alert['level'] }}">
                        <i class="bi {{ $alert['level']==='critical' ? 'bi-exclamation-octagon-fill' : ($alert['level']==='warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill') }}"></i>
                        <div><strong>{{ $alert['title'] }}</strong><p>{{ $alert['detail'] }}</p></div>
                    </div>
                @empty
                    <div class="olt-dash-all-clear"><i class="bi bi-check-circle-fill"></i><span>No active OLT or ONU alerts from the latest stored telemetry.</span></div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="olt-dash-main-grid olt-dash-bottom-grid">
        <article class="olt-dash-panel olt-dash-onu-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon"><i class="bi bi-hdd-stack"></i></span><div><h3>Discovered ONUs</h3><p>Read-only inventory with customer and optical status</p></div></div>
                <span class="olt-dash-pill neutral">{{ number_format($onus->total()) }} records</span>
            </div>
            <form method="GET" action="{{ route('network-inventory.olt.dashboard',$device) }}" class="olt-dash-filter">
                <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input name="q" value="{{ $search }}" class="form-control" placeholder="Search ONU ID, MAC, customer, PPPoE or PON"></div>
                <select name="status" class="form-select"><option value="">All statuses</option><option value="online" @selected($status==='online')>Online</option><option value="offline" @selected($status==='offline')>Offline</option><option value="unknown" @selected($status==='unknown')>Unknown</option></select>
                <button class="btn btn-outline-primary" type="submit">Filter</button>
                @if($search !== '' || $status !== '')<a class="btn btn-outline-secondary" href="{{ route('network-inventory.olt.dashboard',$device) }}">Reset</a>@endif
            </form>
            <div class="olt-dash-table-wrap">
                <table class="table olt-dash-table align-middle mb-0">
                    <thead><tr><th>ONU / Customer</th><th>PON / ONU ID</th><th>MAC / Serial</th><th>RX / TX</th><th>Status</th><th>Last Seen</th></tr></thead>
                    <tbody>
                    @forelse($onus as $onu)
                        @php($opmFresh = $onu->opm_last_seen_at !== null && $onu->opm_last_seen_at->greaterThanOrEqualTo(now()->subMinutes($opmFreshMinutes)))
                        @php($rx = $opmFresh && is_numeric($onu->rx_power) ? (float)$onu->rx_power : null)
                        @php($tx = $opmFresh && is_numeric($onu->tx_power) ? (float)$onu->tx_power : null)
                        @php($signalClass = $rx === null ? 'na' : ($rx >= -20 ? 'good' : ($rx >= -27 ? 'warning' : 'critical')))
                        <tr>
                            <td><strong>{{ $onu->customer?->customer_name ?: 'Unmapped ONU' }}</strong><small>{{ $onu->customer?->customer_unique_id ?: 'No customer ID' }} · PPPoE {{ $onu->pppUser?->username ?: '—' }}</small></td>
                            <td><strong>{{ $onu->pon_port ?: '—' }}</strong><small>ONU {{ $onu->onu_id ?: '—' }} · {{ $onu->onu_type ?: 'Type unavailable' }}</small></td>
                            <td>{{ $onu->onu_mac ?: '—' }}<small>{{ $onu->onu_serial ?: 'Serial unavailable' }}</small></td>
                            <td><span class="olt-dash-rx {{ $signalClass }}">{{ $rx !== null ? 'RX '.number_format($rx,2).' dBm' : 'RX —' }}</span><small>{{ $tx !== null ? 'TX '.number_format($tx,2).' dBm' : 'TX —' }}</small>@if($onu->opm_last_seen_at)<small>OPM {{ $opmFresh ? $onu->opm_last_seen_at->diffForHumans() : 'stale · '.$onu->opm_last_seen_at->diffForHumans() }}</small>@else<small>No OPM timestamp</small>@endif</td>
                            <td><span class="olt-dash-onu-status {{ $onu->status === 'online' ? 'online' : ($onu->status === 'offline' ? 'offline' : 'unknown') }}">{{ ucfirst($onu->status ?: 'unknown') }}</span></td>
                            <td>{{ $onu->last_seen_at?->format('d M Y') ?: '—' }}<small>{{ $onu->last_seen_at?->format('H:i:s') ?: 'Not seen yet' }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="olt-dash-empty"><i class="bi bi-hdd-stack"></i><span>No ONU records found for this filter. Run Sync ONU to refresh the read-only inventory.</span></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($onus->hasPages())<div class="olt-dash-pagination">{{ $onus->links() }}</div>@endif
            <div class="olt-dash-panel-foot">Optical values are shown only when their measurement timestamp is within {{ $opmFreshMinutes }} minutes. Historical values are intentionally hidden from the live signal column.</div>
        </article>

        <aside class="olt-dash-panel olt-dash-device-panel">
            <div class="olt-dash-panel-title">
                <div><span class="olt-dash-panel-icon"><i class="bi bi-cpu"></i></span><div><h3>Device Information</h3><p>Configured inventory and management state</p></div></div>
            </div>
            <dl class="olt-dash-info-list">
                <div><dt>OLT Name</dt><dd>{{ $deviceInfo['name'] }}</dd></div>
                <div><dt>IP Address</dt><dd>{{ $deviceInfo['ip'] ?: 'Not configured' }}</dd></div>
                <div><dt>Vendor / Model</dt><dd>{{ trim(($deviceInfo['vendor'] ?: '—').' '.($deviceInfo['model'] ?: '')) }}</dd></div>
                <div><dt>Firmware</dt><dd>{{ $deviceInfo['firmware'] ?: 'Not recorded' }}</dd></div>
                <div><dt>Serial Number</dt><dd>{{ $deviceInfo['serial'] ?: 'Not recorded' }}</dd></div>
                <div><dt>PON Type</dt><dd>{{ $deviceInfo['olt_type'] ?: 'Not configured' }}</dd></div>
                <div><dt>PON Capacity</dt><dd>{{ $deviceInfo['pon_ports'] !== null ? $deviceInfo['pon_ports'].' ports' : 'Not recorded' }}</dd></div>
                <div><dt>SNMP Port / Version</dt><dd>{{ $deviceInfo['snmp_port'] }} / {{ strtoupper($deviceInfo['snmp_version']) }}</dd></div>
                <div><dt>SNMP Community</dt><dd><span class="olt-dash-configured {{ $deviceInfo['snmp_configured'] ? 'yes' : 'no' }}">{{ $deviceInfo['snmp_configured'] ? 'Configured · protected' : 'Not configured' }}</span></dd></div>
                <div><dt>Last Health Check</dt><dd>{{ $deviceInfo['last_checked_at']?->format('d M Y H:i:s') ?: 'Never' }}</dd></div>
                <div><dt>Latency</dt><dd>{{ $deviceInfo['last_latency_ms'] !== null ? $deviceInfo['last_latency_ms'].' ms' : 'No sample' }}</dd></div>
            </dl>
            <div class="olt-dash-device-actions">
                <a class="btn btn-outline-primary btn-sm" href="{{ route('network-inventory.olt.edit',$device) }}"><i class="bi bi-pencil"></i> Edit OLT</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('network-inventory.olt.customers',$device) }}"><i class="bi bi-people"></i> Mapping</a>
            </div>
        </aside>
    </section>

    <div class="olt-dash-footer-note"><i class="bi bi-shield-check"></i> Read-only monitoring dashboard · No SNMP SET, provisioning or ONU configuration commands are issued by this page. Use the separate Provisioning module for explicitly authorized changes.</div>
</div>
</x-app-layout>
