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
        $monitor->run(
            'queue-worker-heartbeat',
            'Database Queue Worker Heartbeat',
            'Every 5 minutes',
            fn () => [
                'message' => 'A database queue worker successfully processed the heartbeat job.',
                'metadata' => ['checked_at' => now()->toIso8601String()],
            ]
        );
    }
}