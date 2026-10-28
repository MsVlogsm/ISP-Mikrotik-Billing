<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToProfileIfNoPermissions;
use App\Models\AutomationSyncStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemSyncHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RestrictToProfileIfNoPermissions::class);

        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_expected_schedules_are_visible_before_their_first_run(): void
    {
        config(['queue.default' => 'database']);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()
            ->assertSee('Scheduler Heartbeat')
            ->assertSee('Waiting')
            ->assertSee('Queue Worker Heartbeat')
            ->assertSee('Expected Tasks');

        $response->assertViewHas('tasks', function ($tasks): bool {
            $scheduler = collect($tasks)->first(
                fn (array $row) => $row['task']->task_key === 'scheduler-heartbeat'
            );

            return $scheduler !== null
                && $scheduler['expected'] === true
                && $scheduler['health'] === 'waiting';
        });
    }

    public function test_recently_running_task_is_not_misreported_as_healthy(): void
    {
        config(['queue.default' => 'database']);
        AutomationSyncStatus::create([
            'task_key' => 'scheduler-heartbeat',
            'label' => 'Scheduler Heartbeat',
            'cadence' => 'Every minute',
            'status' => 'running',
            'last_started_at' => now()->subMinute(),
            'last_success_at' => now()->subMinutes(2),
            'runs_count' => 3,
            'failures_count' => 0,
        ]);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()->assertViewHas('tasks', function ($tasks): bool {
            $scheduler = collect($tasks)->first(
                fn (array $row) => $row['task']->task_key === 'scheduler-heartbeat'
            );

            return $scheduler !== null && $scheduler['health'] === 'running';
        });
    }

    public function test_sync_queue_driver_marks_worker_health_unverified(): void
    {
        config(['queue.default' => 'sync']);
        AutomationSyncStatus::create([
            'task_key' => 'queue-worker-heartbeat',
            'label' => 'Queue Worker Heartbeat',
            'cadence' => 'Every 5 minutes',
            'status' => 'success',
            'last_started_at' => now()->subMinute(),
            'last_success_at' => now()->subMinute(),
            'runs_count' => 4,
            'failures_count' => 0,
        ]);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()
            ->assertSee('not independently verifiable')
            ->assertViewHas('tasks', function ($tasks): bool {
                $worker = collect($tasks)->first(
                    fn (array $row) => $row['task']->task_key === 'queue-worker-heartbeat'
                );

                return $worker !== null && $worker['health'] === 'unverified';
            });
    }

    public function test_unregistered_historical_rows_remain_visible(): void
    {
        config(['queue.default' => 'database']);
        AutomationSyncStatus::create([
            'task_key' => 'retired-background-task',
            'label' => 'Retired Background Task',
            'cadence' => 'Every minute',
            'status' => 'success',
            'last_started_at' => now()->subMinute(),
            'last_success_at' => now()->subMinute(),
            'runs_count' => 1,
            'failures_count' => 0,
        ]);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()->assertSee('Retired Background Task')->assertSee('Unregistered');
        $response->assertViewHas('tasks', function ($tasks): bool {
            $task = collect($tasks)->first(
                fn (array $row) => $row['task']->task_key === 'retired-background-task'
            );

            return $task !== null && $task['expected'] === false;
        });
    }

    public function test_scheduler_heartbeat_is_stale_after_multiple_expected_intervals_are_missed(): void
    {
        config(['queue.default' => 'database']);
        AutomationSyncStatus::create([
            'task_key' => 'scheduler-heartbeat',
            'label' => 'Scheduler Heartbeat',
            'cadence' => 'Every minute',
            'status' => 'success',
            'last_started_at' => now()->subMinutes(4),
            'last_success_at' => now()->subMinutes(4),
            'runs_count' => 12,
            'failures_count' => 0,
        ]);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()->assertViewHas('tasks', function ($tasks): bool {
            $scheduler = collect($tasks)->first(
                fn (array $row) => $row['task']->task_key === 'scheduler-heartbeat'
            );

            return $scheduler !== null
                && $scheduler['health'] === 'stale'
                && $scheduler['threshold_seconds'] === 180;
        });
    }

    public function test_long_running_schedule_is_marked_stalled(): void
    {
        config(['queue.default' => 'database']);
        AutomationSyncStatus::create([
            'task_key' => 'scheduler-heartbeat',
            'label' => 'Scheduler Heartbeat',
            'cadence' => 'Every minute',
            'status' => 'running',
            'last_started_at' => now()->subMinutes(20),
            'last_success_at' => now()->subMinutes(22),
            'runs_count' => 12,
            'failures_count' => 0,
        ]);

        $response = $this->get(route('system.sync-health'));

        $response->assertOk()->assertViewHas('tasks', function ($tasks): bool {
            $scheduler = collect($tasks)->first(
                fn (array $row) => $row['task']->task_key === 'scheduler-heartbeat'
            );

            return $scheduler !== null && $scheduler['health'] === 'stalled';
        });
    }

}