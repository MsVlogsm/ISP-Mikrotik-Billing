<?php

namespace App\Http\Controllers;

use App\Models\AutomationSyncStatus;
use Illuminate\Http\Request;

class SystemSyncHealthController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('Super Admin'), 403);

        $now = now();
        $tasks = AutomationSyncStatus::query()->orderBy('label')->get()->map(function (AutomationSyncStatus $task) use ($now) {
            $thresholdSeconds = match (true) {
                str_contains(strtolower((string) $task->cadence), 'every minute') => 180,
                str_contains(strtolower((string) $task->cadence), '5 minutes') => 900,
                str_contains(strtolower((string) $task->cadence), '15 minutes') => 2700,
                str_contains(strtolower((string) $task->cadence), 'daily') => 36 * 3600,
                str_contains(strtolower((string) $task->cadence), 'monthly') => 40 * 86400,
                default => 3600,
            };

            if ($task->status === 'running' && $task->last_started_at?->lt($now->copy()->subMinutes(15))) {
                $health = 'stalled';
            } elseif ($task->last_failed_at && (! $task->last_success_at || $task->last_failed_at->gt($task->last_success_at))) {
                $health = 'failed';
            } elseif (! $task->last_success_at) {
                $health = 'waiting';
            } elseif ($task->last_success_at->lt($now->copy()->subSeconds($thresholdSeconds))) {
                $health = 'stale';
            } else {
                $health = 'healthy';
            }

            return [
                'task' => $task,
                'health' => $health,
                'threshold_seconds' => $thresholdSeconds,
            ];
        });

        $integrityTask = AutomationSyncStatus::query()->where('task_key', 'data-integrity-audit')->first();
        $integrity = $integrityTask?->last_metadata ?? [];
        $checks = collect($integrity['checks'] ?? []);
        $openFindings = $checks->sum(fn ($check) => (int) ($check['count'] ?? 0));

        return view('xlink.system-sync-health', [
            'tasks' => $tasks,
            'taskCount' => $tasks->count(),
            'healthyCount' => $tasks->where('health', 'healthy')->count(),
            'failedCount' => $tasks->where('health', 'failed')->count(),
            'staleCount' => $tasks->whereIn('health', ['stale', 'stalled'])->count(),
            'waitingCount' => $tasks->where('health', 'waiting')->count(),
            'integrity' => $integrity,
            'integrityTask' => $integrityTask,
            'integrityChecks' => $checks,
            'openFindings' => $openFindings,
        ]);
    }
}