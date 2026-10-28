<?php

namespace App\Jobs;

use App\Services\AutomationSyncMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class QueueWorkerHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 20;

    public function handle(AutomationSyncMonitor $monitor): void
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $message = $queueConnection === 'sync'
            ? 'Heartbeat job ran inline using the sync queue; this does not verify an independent queue worker.'
            : 'Heartbeat job was processed using the configured '.$queueConnection.' queue connection.';

        $monitor->run(
            'queue-worker-heartbeat',
            'Queue Worker Heartbeat',
            'Every 5 minutes',
            fn () => [
                'message' => $message,
                'metadata' => [
                    'checked_at' => now()->toIso8601String(),
                    'queue_connection' => $queueConnection,
                    'queue_name' => $this->queue,
                ],
            ]
        );
    }
}