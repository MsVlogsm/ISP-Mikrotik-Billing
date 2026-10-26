<?php

namespace Tests\Feature;

use App\Models\AutomationSyncStatus;
use App\Services\AutomationSyncMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AutomationSyncMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_run_records_last_success_and_metadata(): void
    {
        $result = app(AutomationSyncMonitor::class)->run(
            'example-sync',
            'Example Sync',
            'Every 5 minutes',
            fn () => ['message' => 'Sync completed', 'metadata' => ['processed' => 12]]
        );

        $this->assertSame('Sync completed', $result['message']);

        $status = AutomationSyncStatus::query()->where('task_key', 'example-sync')->firstOrFail();
        $this->assertSame('success', $status->status);
        $this->assertSame(1, $status->runs_count);
        $this->assertSame(0, $status->failures_count);
        $this->assertNotNull($status->last_started_at);
        $this->assertNotNull($status->last_success_at);
        $this->assertSame(12, $status->last_metadata['processed']);
    }

    public function test_failed_run_is_recorded_and_exception_is_rethrown(): void
    {
        try {
            app(AutomationSyncMonitor::class)->run(
                'failing-sync',
                'Failing Sync',
                'Every minute',
                fn () => throw new RuntimeException('Probe failed')
            );
            $this->fail('Expected the scheduled exception to be re-thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Probe failed', $exception->getMessage());
        }

        $status = AutomationSyncStatus::query()->where('task_key', 'failing-sync')->firstOrFail();
        $this->assertSame('failed', $status->status);
        $this->assertSame(1, $status->runs_count);
        $this->assertSame(1, $status->failures_count);
        $this->assertNotNull($status->last_failed_at);
    }
}