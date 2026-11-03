<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Jobs\ReconcileMikrotikPendingAction;
use App\Models\RouterList;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MikrotikPendingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_only_pending_actions_for_connected_routers(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        RouterList::create([
            'router_name' => 'disconnected-router',
            'ip_address' => '127.0.0.2',
            'username' => 'test',
            'password' => 'test',
            'action' => 'disconnected',
        ]);

        $connected = $this->pending('connected-router', 'queued-user', 'disable');
        $this->pending('disconnected-router', 'not-queued-user', 'disable');
        $this->pending('connected-router', 'completed-user', 'remove', 'completed');

        Queue::fake();

        $this->artisan('app:reconcile-mikrotik-pending-actions')
            ->assertSuccessful();

        Queue::assertPushed(ReconcileMikrotikPendingAction::class, function ($job) use ($connected) {
            return $job->pendingActionId === $connected;
        });
        Queue::assertPushedTimes(ReconcileMikrotikPendingAction::class, 1);
    }

    public function test_job_disables_pending_action_when_router_is_connected(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        $id = $this->pending('connected-router', 'queued-user', 'disable');
        CustomersInfo::create([
            'customer_unique_id' => 'JOB-DISABLE',
            'customer_name' => 'Queued disable customer',
            'status' => 'active',
            'disable_count' => 0,
        ]);
        BillingInfo::create([
            'customer_bill_unique_id' => 'JOB-DISABLE',
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldReceive('disablePPPSecret')
            ->once()
            ->with('JOB-DISABLE', 'connected-router', 'queued-user', false, true)
            ->andReturn('soft');
        $this->app->instance(MikrotikController::class, $controller);

        (new ReconcileMikrotikPendingAction($id))->handle($controller);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'completed',
            'attempts' => 1,
            'last_error' => null,
        ]);
    }

    public function test_job_supersedes_prepaid_disable_when_new_expiry_is_in_the_future(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        CustomersInfo::create([
            'customer_unique_id' => 'PENDING-future-user',
            'customer_name' => 'Renewed prepaid customer',
            'status' => 'active',
            'disable_count' => 0,
        ]);
        BillingInfo::create([
            'customer_bill_unique_id' => 'PENDING-future-user',
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 0,
            'paid_amount' => 500,
            'auto_disable' => true,
            'auto_disable_date' => today()->addMonth()->toDateString(),
        ]);

        $id = $this->pending('connected-router', 'future-user', 'disable');
        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldNotReceive('disablePPPSecret');

        (new ReconcileMikrotikPendingAction($id))->handle($controller);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'superseded',
            'attempts' => 0,
        ]);
    }

    public function test_job_uses_connection_day_when_prepaid_expiry_is_missing(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        $customerId = 'PENDING-missing-expiry-user';
        CustomersInfo::create([
            'customer_unique_id' => $customerId,
            'customer_name' => 'Missing expiry customer',
            'connection_date' => today()->subMonthNoOverflow()->toDateString(),
            'status' => 'active',
            'disable_count' => 0,
        ]);
        BillingInfo::create([
            'customer_bill_unique_id' => $customerId,
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
            'auto_disable' => true,
            'auto_disable_date' => null,
        ]);

        $id = $this->pending('connected-router', 'missing-expiry-user', 'disable');
        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldReceive('disablePPPSecret')
            ->once()
            ->with($customerId, 'connected-router', 'missing-expiry-user', false, true)
            ->andReturn('soft');

        (new ReconcileMikrotikPendingAction($id))->handle($controller);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'completed',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('customers_infos', [
            'customer_unique_id' => $customerId,
            'status' => 'disable',
        ]);
    }

    public function test_job_removes_pending_action_when_router_is_connected(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        $id = $this->pending('connected-router', 'delete-user', 'remove');

        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldReceive('removePPPSecret')
            ->once()
            ->with('JOB-REMOVE', 'connected-router', 'delete-user')
            ->andReturnNull();

        (new ReconcileMikrotikPendingAction($id))->handle($controller);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'completed',
            'attempts' => 1,
        ]);
    }

    public function test_job_leaves_action_pending_when_router_is_disconnected(): void
    {
        RouterList::create([
            'router_name' => 'disconnected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'disconnected',
        ]);
        $id = $this->pending('disconnected-router', 'offline-user', 'disable');

        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldNotReceive('disablePPPSecret');

        (new ReconcileMikrotikPendingAction($id))->handle($controller);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'pending',
            'attempts' => 0,
            'processed_at' => null,
        ]);
    }

    public function test_job_records_error_and_rethrows_when_remote_action_fails(): void
    {
        RouterList::create([
            'router_name' => 'connected-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
        $id = $this->pending('connected-router', 'failing-user', 'disable');
        CustomersInfo::create([
            'customer_unique_id' => 'PENDING-failing-user',
            'customer_name' => 'Failing disable customer',
            'status' => 'active',
            'disable_count' => 0,
        ]);
        BillingInfo::create([
            'customer_bill_unique_id' => 'PENDING-failing-user',
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        $controller = Mockery::mock(MikrotikController::class);
        $controller->shouldReceive('disablePPPSecret')
            ->once()
            ->andThrow(new \RuntimeException('simulated router failure'));

        try {
            (new ReconcileMikrotikPendingAction($id))->handle($controller);
            $this->fail('Expected remote action failure to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated router failure', $e->getMessage());
        }

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $id,
            'status' => 'pending',
            'attempts' => 1,
            'last_error' => 'simulated router failure',
            'processed_at' => null,
        ]);
    }

    private function pending(string $router, string $username, string $action, string $status = 'pending'): int
    {
        return (int) \DB::table('mikrotik_pending_actions')->insertGetId([
            'customer_unique_id' => $action === 'remove' ? 'JOB-REMOVE' : ($username === 'queued-user' ? 'JOB-DISABLE' : 'PENDING-'.$username),
            'router_name' => $router,
            'username' => $username,
            'action' => $action,
            'status' => $status,
            'attempts' => 0,
            'last_error' => null,
            'processed_at' => $status === 'completed' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
