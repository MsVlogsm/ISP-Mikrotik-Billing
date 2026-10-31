<x-app-layout>
    <x-slot name="header">Automation & Sync Health</x-slot>
    <div class="container-fluid px-1">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h3 class="mb-1"><i class="bi bi-arrow-repeat me-2"></i>Automation & Sync Health</h3>
                <div class="text-muted">Scheduled run status, sync freshness and read-only data integrity checks.</div>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('system.sync-health') }}"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Expected Tasks</div><div class="fs-2 fw-bold">{{ $expectedTaskCount }}</div><div class="small text-muted">{{ $taskCount }} rows shown · {{ $unregisteredCount }} unregistered</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Healthy</div><div class="fs-2 fw-bold text-success">{{ $healthyCount }}</div><div class="small text-muted">Last successful run is fresh</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Running</div><div class="fs-2 fw-bold text-info">{{ $runningCount }}</div><div class="small text-muted">Active runs, not assumed healthy</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Failed / Stale</div><div class="fs-2 fw-bold {{ ($failedCount + $staleCount) ? 'text-danger' : 'text-success' }}">{{ $failedCount + $staleCount }}</div><div class="small text-muted">{{ $failedCount }} failed · {{ $staleCount }} stale/stalled</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Waiting / Unverified</div><div class="fs-2 fw-bold text-secondary">{{ $waitingCount + $unverifiedCount }}</div><div class="small text-muted">{{ $waitingCount }} waiting · {{ $unverifiedCount }} unverified</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Integrity Findings</div><div class="fs-2 fw-bold {{ $openFindings ? 'text-warning' : 'text-success' }}">{{ $openFindings }}</div><div class="small text-muted">Read-only checks; no automatic data repair</div></div></div></div>
        </div>

        @if($queueConnection === 'sync')
            <div class="alert alert-warning">
                <strong>Queue worker status is not independently verifiable.</strong>
                The configured <code>sync</code> driver runs jobs inline. The heartbeat is therefore marked Unverified rather than Healthy; it does not prove that a separate background worker is running.
            </div>
        @else
            <div class="alert alert-info">
                Queue connection: <strong>{{ $queueConnection }}</strong>. The queue heartbeat is tracked separately from the scheduler dispatch; a fresh worker heartbeat indicates that a job was processed on this configured queue connection.
            </div>
        @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <div class="fw-semibold">Scheduled Background Tasks</div>
                <div class="small text-muted">Expected schedules are shown even before their first run. A stale scheduler heartbeat means the scheduler/cron path may not be executing; unregistered rows remain visible for review.</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Task</th><th>Registry</th><th>Schedule</th><th>Status</th><th>Last started</th><th>Last success</th><th>Success age</th><th>Last duration</th><th>Latest result</th></tr></thead>
                    <tbody>
                    @forelse($tasks as $row)
                        @php
                            $badge = match($row['health']) {
                                'healthy' => 'success',
                                'running' => 'info',
                                'failed', 'stalled' => 'danger',
                                'stale' => 'warning',
                                'waiting', 'unverified' => 'secondary',
                                default => 'secondary',
                            };
                        @endphp
                        <tr>
                            <td class="fw-semibold">{{ $row['task']->label }}</td>
                            <td><span class="badge bg-{{ $row['expected'] ? 'info' : 'secondary' }}-subtle text-{{ $row['expected'] ? 'info' : 'secondary' }}">{{ $row['expected'] ? 'Expected' : 'Unregistered' }}</span></td>
                            <td class="small text-muted">{{ $row['task']->cadence ?: '—' }}</td>
                            <td><span class="badge bg-{{ $badge }}-subtle text-{{ $badge }}">{{ ucfirst($row['health']) }}</span></td>
                            <td class="small">{{ $row['task']->last_started_at?->format('d M Y H:i:s') ?? 'Never' }}</td>
                            <td class="small">{{ $row['task']->last_success_at?->format('d M Y H:i:s') ?? 'Never' }}</td>
                            <td class="small">{{ $row['last_success_age'] }}</td>
                            <td class="small">{{ $row['task']->last_duration_ms !== null ? number_format($row['task']->last_duration_ms).' ms' : '—' }}</td>
                            <td class="small" style="min-width:240px;max-width:420px;white-space:normal;overflow-wrap:anywhere">{{ $row['task']->last_message ?: 'Waiting for first run' }}@if($row['task']->failures_count)<div class="text-danger mt-1">Failures: {{ number_format($row['task']->failures_count) }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No scheduled-task definitions are available.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                <div><div class="fw-semibold">Data Integrity Audit</div><div class="small text-muted">Runs every 15 minutes. It reports problems and does not edit customer/router/mapping records.</div></div>
                @php $integrityHealth = $integrity['health'] ?? 'waiting'; @endphp
                <span class="badge bg-{{ $integrityHealth === 'healthy' ? 'success' : ($integrityHealth === 'critical' ? 'danger' : ($integrityHealth === 'warning' ? 'warning' : 'secondary')) }}-subtle text-{{ $integrityHealth === 'healthy' ? 'success' : ($integrityHealth === 'critical' ? 'danger' : ($integrityHealth === 'warning' ? 'warning' : 'secondary')) }}">{{ ucfirst($integrityHealth) }}</span>
            </div>
            <div class="card-body">
                @if(!empty($integrity['checked_at']))
                    <div class="small text-muted mb-3">Last checked: {{ \Illuminate\Support\Carbon::parse($integrity['checked_at'])->format('d M Y H:i:s') }} · Findings: {{ number_format((int)($integrity['finding_count'] ?? 0)) }} · Critical: {{ number_format((int)($integrity['critical_count'] ?? 0)) }}</div>
                @else
                    <div class="alert alert-secondary">The first audit will appear after the scheduled task runs.</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Check</th><th>Severity</th><th>Findings</th><th>Meaning</th></tr></thead>
                        <tbody>
                        @forelse($integrityChecks as $check)
                            <tr>
                                <td>{{ $check['label'] ?? 'Integrity check' }}</td>
                                <td><span class="badge bg-{{ ($check['severity'] ?? '') === 'critical' ? 'danger' : 'warning' }}-subtle text-{{ ($check['severity'] ?? '') === 'critical' ? 'danger' : 'warning' }}">{{ ucfirst($check['severity'] ?? 'info') }}</span></td>
                                <td class="fw-semibold {{ (int)($check['count'] ?? 0) ? 'text-danger' : 'text-success' }}">{{ number_format((int)($check['count'] ?? 0)) }}</td>
                                <td class="small text-muted">{{ $check['detail'] ?? '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No audit result available yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="fw-semibold">Stale ONU Telemetry Details</div>
                    <div class="small text-muted">Read-only evidence for ONUs still recorded as online/unknown but not seen in the last 5 minutes.</div>
                </div>
                <span class="badge bg-{{ $staleOnuTotal ? 'warning' : 'success' }}-subtle text-{{ $staleOnuTotal ? 'warning' : 'success' }}">{{ number_format($staleOnuTotal) }} finding(s)</span>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-3">A missed ONU can mean stale inventory, a partial poll, or a real status change. This panel does not automatically mark ONUs offline or alter customer mappings. @if($staleOnuTotal > $staleOnuMappings->count()) Showing {{ $staleOnuMappings->count() }} of {{ number_format($staleOnuTotal) }} records. @endif</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>OLT</th><th>ONU ID</th><th>MAC / Serial</th><th>Recorded status</th><th>Last seen</th><th>Optical reading</th><th>RX / TX (dBm)</th></tr></thead>
                        <tbody>
                        @forelse($staleOnuMappings as $mapping)
                            <tr>
                                <td>{{ $mapping->olt?->name ?? ('OLT #'.$mapping->olt_device_id) }}<div class="small text-muted">{{ $mapping->olt?->vendor }} {{ $mapping->olt?->model }}</div></td>
                                <td>{{ $mapping->onu_id ?: '—' }}</td>
                                <td class="small">{{ $mapping->onu_mac ?: 'No MAC' }}@if($mapping->onu_serial)<div class="text-muted">{{ $mapping->onu_serial }}</div>@endif</td>
                                <td><span class="badge bg-warning-subtle text-warning">{{ ucfirst($mapping->status ?: 'unknown') }}</span></td>
                                <td class="small">{{ $mapping->last_seen_at?->format('d M Y H:i:s') ?? 'Never recorded' }}</td>
                                <td class="small">{{ $mapping->opm_last_seen_at?->format('d M Y H:i:s') ?? 'No optical timestamp' }}@if($mapping->opm_source)<div class="text-muted">{{ $mapping->opm_source }}</div>@endif</td>
                                <td class="small">RX: {{ $mapping->rx_power ?? '—' }}<br>TX: {{ $mapping->tx_power ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No stale online/unknown ONU records detected.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-info">
            <strong>Live telemetry is separate:</strong> Dashboard PPP session telemetry is fetched on demand and cached for 30 seconds; the Online Customers page polls sessions on its own request. This page tracks scheduled jobs and data quality, not a forced router refresh.
        </div>
    </div>
</x-app-layout>