<?php

namespace App\Http\Controllers;

use App\Models\AutomationSyncStatus;
use Illuminate\Http\Request;

class SystemSyncHealthController extends Controller
{
    /**
     * Expected schedules are listed independently from run records. A missing row
     * means that a task has not yet recorded a run, not that the task does not exist.
     * Keep the keys aligned with routes/console.php.
     */
    private const EXPECTED_TASKS = [
        'scheduler-heartbeat' => ['label' => 'Scheduler Heartbeat', 'cadence' => 'Every minute'],
        'monthly-bill-generation' => ['label' => 'Monthly Bill Generation', 'cadence' => 'Monthly · last day 23:45'],
        'monthly-bill-sms' => ['label' => 'Monthly Bill SMS', 'cadence' => 'Monthly · day 1 at 10:00'],
        'daily-due-warning-sms' => ['label' => 'Due / Disconnection Warning SMS', 'cadence' => 'Daily · 08:00'],
        'auto-disable-customers' => ['label' => 'Automatic Customer Disable', 'cadence' => 'Every 5 minutes'],
        'mikrotik-ppp-secret-sync' => ['label' => 'MikroTik PPP Secret Sync', 'cadence' => 'Daily · 00:00'],
        'mikrotik-router-log-poll' => ['label' => 'MikroTik Router Log Poll', 'cadence' => 'Every minute'],
        'expire-realtime-hotspot-users' => ['label' => 'Realtime Hotspot Voucher Sync / Expiry', 'cadence' => 'Every minute'],
        'check-device-watchers' => ['label' => 'Device Watcher Health Check', 'cadence' => 'Every minute'],
        'prune-router-logs' => ['label' => 'Old Router Log Cleanup', 'cadence' => 'Daily · 04:00'],
        'check-network-inventory' => ['label' => 'Network Inventory Health Check', 'cadence' => 'Every minute'],
        'reconcile-mikrotik-pending-actions' => ['label' => 'Pending MikroTik Action Reconciliation', 'cadence' => 'Every minute'],
        'onu-mapping-sync' => ['label' => 'OLT / ONU Mapping & Telemetry Sync', 'cadence' => 'Every minute'],
        'customer-connection-snapshots' => ['label' => 'Customer Online / Offline Snapshots', 'cadence' => 'Every 5 minutes'],
        'due-call-reminders' => ['label' => 'Due Customer Voice Call Reminder', 'cadence' => 'Daily · 11:00'],
        'queue-heartbeat-dispatch' => ['label' => 'Queue Worker Heartbeat Dispatch', 'cadence' => 'Every 5 minutes'],
        'queue-worker-heartbeat' => ['label' => 'Queue Worker Heartbeat', 'cadence' => 'Every 5 minutes'],
        'data-integrity-audit' => ['label' => 'Read-only Data Integrity Audit', 'cadence' => 'Every 15 minutes'],
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('Super Admin'), 403);

        $now = now();
        $queueConnection = (string) config('queue.default', 'sync');
        $storedTasks = AutomationSyncStatus::query()->orderBy('label')->get()->keyBy('task_key');

        $expectedTasks = collect(self::EXPECTED_TASKS)->map(function (array $definition, string $taskKey) use ($storedTasks) {
            $task = $storedTasks->pull($taskKey);

            if (! $task) {
                $task = new AutomationSyncStatus(array_merge($definition, [
                    'task_key' => $taskKey,
                    'status' => 'never',
                    'runs_count' => 0,
                    'failures_count' => 0,
                ]));
            }

            return ['task' => $task, 'expected' => true];
        });

        // Keep historical/unregistered rows visible rather than hiding an old job
        // or a task whose schedule registration was accidentally removed.
        $unregisteredTasks = $storedTasks->values()->map(
            fn (AutomationSyncStatus $task) => ['task' => $task, 'expected' => false]
        );

        $tasks = $expectedTasks->concat($unregisteredTasks)->map(function (array $row) use ($now, $queueConnection) {
            /** @var AutomationSyncStatus $task */
            $task = $row['task'];
            $thresholdSeconds = $this->thresholdSecondsFor($task->cadence);
            $latestFailure = $task->last_failed_at
                && (! $task->last_success_at || $task->last_failed_at->gt($task->last_success_at));

            // The sync driver executes queued jobs inline; it cannot prove that
            // an independent, long-running queue worker is healthy.
            if ($task->task_key === 'queue-worker-heartbeat' && $queueConnection === 'sync' && ! $latestFailure) {
                $health = 'unverified';
            } elseif ($task->status === 'running') {
                $health = $task->last_started_at
                    && $task->last_started_at->lt($now->copy()->subMinutes(15))
                    ? 'stalled'
                    : 'running';
            } elseif ($latestFailure) {
                $health = 'failed';
            } elseif (! $task->last_success_at) {
                $health = 'waiting';
            } elseif ($task->last_success_at->lt($now->copy()->subSeconds($thresholdSeconds))) {
                $health = 'stale';
            } else {
                $health = 'healthy';
            }

            $lastSuccessAgeSeconds = $task->last_success_at
                ? max(0, (int) $task->last_success_at->diffInSeconds($now))
                : null;

            return [
                'task' => $task,
                'expected' => $row['expected'],
                'health' => $health,
                'threshold_seconds' => $thresholdSeconds,
                'last_success_age_seconds' => $lastSuccessAgeSeconds,
                'last_success_age' => $this->formatAge($lastSuccessAgeSeconds),
            ];
        })->sortBy(fn (array $row) => strtolower((string) $row['task']->label))->values();

        $integrityTask = AutomationSyncStatus::query()->where('task_key', 'data-integrity-audit')->first();
        $integrity = $integrityTask?->last_metadata ?? [];
        $checks = collect($integrity['checks'] ?? []);
        $openFindings = $checks->sum(fn ($check) => (int) ($check['count'] ?? 0));

        return view('xlink.system-sync-health', [
            'tasks' => $tasks,
            'taskCount' => $tasks->count(),
            'expectedTaskCount' => count(self::EXPECTED_TASKS),
            'unregisteredCount' => $tasks->where('expected', false)->count(),
            'healthyCount' => $tasks->where('health', 'healthy')->count(),
            'runningCount' => $tasks->where('health', 'running')->count(),
            'failedCount' => $tasks->where('health', 'failed')->count(),
            'staleCount' => $tasks->whereIn('health', ['stale', 'stalled'])->count(),
            'waitingCount' => $tasks->where('health', 'waiting')->count(),
            'unverifiedCount' => $tasks->where('health', 'unverified')->count(),
            'queueConnection' => $queueConnection,
            'integrity' => $integrity,
            'integrityTask' => $integrityTask,
            'integrityChecks' => $checks,
            'openFindings' => $openFindings,
        ]);
    }

    private function thresholdSecondsFor(?string $cadence): int
    {
        $cadence = strtolower((string) $cadence);

        return match (true) {
            str_contains($cadence, 'every minute') => 180,
            str_contains($cadence, '5 minutes') => 900,
            str_contains($cadence, '15 minutes') => 2700,
            str_contains($cadence, 'hourly') => 3 * 3600,
            str_contains($cadence, 'daily') => 36 * 3600,
            str_contains($cadence, 'monthly') => 40 * 86400,
            default => 3600,
        };
    }

    private function formatAge(?int $seconds): string
    {
        if ($seconds === null) {
            return 'Never';
        }

        if ($seconds < 60) {
            return $seconds.' sec';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600).' hr';
        }

        return intdiv($seconds, 86400).' d';
    }
}