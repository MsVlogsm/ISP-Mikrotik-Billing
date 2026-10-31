<x-app-layout>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h2 class="mb-1">Network Operations</h2><p class="text-muted mb-0">Operational view of recorded network events and background-sync health.</p></div>
        <a href="{{ route('system.sync-health') }}" class="btn btn-outline-primary">Automation & Sync Health</a>
    </div>
    <div class="row g-3 mb-4">
        @foreach([['Open Events',$stats['open_events']],['Acknowledged',$stats['acknowledged_events']],['Resolved',$stats['resolved_events']],['Critical / Disaster',$stats['critical_events']],['Healthy Sync Tasks',$stats['sync_healthy']],['Failed Sync Tasks',$stats['sync_failed']]] as [$label,$value])
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="small text-muted">{{ $label }}</div><div class="fs-3 fw-semibold">{{ number_format($value) }}</div></div></div></div>
        @endforeach
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Latest Network Events</div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Last Seen</th><th>Device</th><th>Event</th><th>Severity</th><th>Status</th><th>Occurrences</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr><td>{{ $event->last_seen_at?->format('d M Y H:i:s') ?? '—' }}</td><td>{{ $event->device?->name ?? 'Unknown device' }}</td><td><strong>{{ $event->title }}</strong><div class="small text-muted">{{ \Illuminate\Support\Str::limit($event->message,100) }}</div></td><td>{{ ucfirst($event->severity) }}</td><td>{{ ucfirst($event->status) }}</td><td>{{ $event->occurrences }}</td></tr>
            @empty<tr><td colspan="6" class="text-center text-muted py-4">No network events have been recorded.</td></tr>@endforelse
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="card-header fw-semibold">Background Sync Health</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Task</th><th>Cadence</th><th>Status</th><th>Last Success</th><th>Last Failure</th><th>Last Message</th></tr></thead>
            <tbody>
            @forelse($syncStatuses as $task)
                <tr><td><strong>{{ $task->label }}</strong><div class="small text-muted">{{ $task->task_key }}</div></td><td>{{ $task->cadence }}</td><td><span class="badge {{ $task->status==='success'?'bg-success':($task->status==='failed'?'bg-danger':'bg-secondary') }}">{{ $task->status }}</span></td><td>{{ $task->last_success_at?->diffForHumans() ?? '—' }}</td><td>{{ $task->last_failed_at?->diffForHumans() ?? '—' }}</td><td class="small">{{ \Illuminate\Support\Str::limit($task->last_message,100) }}</td></tr>
            @empty<tr><td colspan="6" class="text-center text-muted py-4">No automation status has been recorded yet.</td></tr>@endforelse
            </tbody>
        </table></div>
    </div>
</div>
</x-app-layout>
