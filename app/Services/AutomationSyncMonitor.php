<?php

namespace App\Services;

use App\Models\AutomationSyncStatus;
use Illuminate\Support\Str;
use Throwable;

class AutomationSyncMonitor
{
    /**
     * Execute a scheduled operation and persist its latest run state.
     * Operational exceptions are re-thrown so Laravel's scheduler can also record
     * the failure; the database row gives administrators a durable status page.
     */
    public function run(string $key, string $label, string $cadence, callable $callback): mixed
    {
        $status = AutomationSyncStatus::query()->firstOrNew(['task_key' => $key]);
        $status->label = $label;
        $status->cadence = $cadence;
        $status->status = 'running';
        $status->last_started_at = now();
        $status->runs_count = (int) $status->runs_count + 1;
        $status->last_message = null;
        $status->save();

        $started = microtime(true);

        try {
            $result = $callback();

            $status->status = 'success';
            $status->last_success_at = now();
            $status->last_duration_ms = max(0, (int) round((microtime(true) - $started) * 1000));

            if (is_array($result)) {
                $message = $result['message'] ?? 'Task completed successfully.';
                $metadata = $result['metadata'] ?? null;
                $status->last_metadata = is_array($metadata) ? $metadata : null;
            } elseif (is_string($result) && trim($result) !== '') {
                $message = $result;
                $status->last_metadata = null;
            } else {
                $message = 'Task completed successfully.';
                $status->last_metadata = null;
            }

            $status->last_message = Str::limit(trim((string) $message), 2000, '…');
            $status->save();

            return $result;
        } catch (Throwable $exception) {
            $status->status = 'failed';
            $status->last_failed_at = now();
            $status->failures_count = (int) $status->failures_count + 1;
            $status->last_duration_ms = max(0, (int) round((microtime(true) - $started) * 1000));
            $status->last_message = Str::limit($exception->getMessage(), 2000, '…');
            $status->last_metadata = ['exception' => get_class($exception)];
            $status->save();

            throw $exception;
        }
    }
}