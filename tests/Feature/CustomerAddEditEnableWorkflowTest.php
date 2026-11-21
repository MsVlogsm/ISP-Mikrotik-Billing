<?php

namespace Tests\Feature;

use App\Livewire\CustomerList;
use App\Livewire\EditCustomer;
use App\Livewire\NewCustomer;
use App\Http\Controllers\MikrotikController;
use App\Jobs\ReconcileMikrotikPendingAction;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAddEditEnableWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['email_verified_at' => now()]);
        $this->superAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->superAdmin);
    }

    private function createCustomer(string $id, string $status = 'disable', ?int $pppUserId = null): CustomersInfo
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Workflow '.$id,
            'mobile' => null,
            'identification_no' => 'N'.$id,
            'status' => $status,
            'disable_count' => 3,
            'ppp_user_id' => $pppUserId,
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $id,
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        return $customer;
    }

    private function disconnectedRouter(string $name): RouterList
    {
        return RouterList::create([
            'router_name' => $name,
            'ip_address' => '127.0.0.1',
            'username' => 'workflow-user',
            'password' => 'workflow-password',
            'action' => 'disconnected',
        ]);
    }

    public function test_add_customer_uses_manual_unique_id_and_allows_optional_mobile_and_short_identification_number(): void
    {
        Livewire::test(NewCustomer::class)
            ->set('customer_name', 'Manual ID Customer')
            ->set('customer_unique_id', 'MANUAL-CID-101')
            ->set('mobile', '')
            ->set('identification_no', 'NID7')
            ->set('monthly_rent', 500)
            ->set('total_amount', 500)
            ->call('save')
            ->assertRedirect('/customers');

        $this->assertDatabaseHas('customers_infos', [
            'customer_unique_id' => 'MANUAL-CID-101',
            'customer_name' => 'Manual ID Customer',
            'mobile' => null,
            'identification_no' => 'NID7',
        ]);
        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => 'MANUAL-CID-101',
            'monthly_rent' => 500,
            'billing_type' => 'prepaid',
        ]);
        $this->assertDatabaseHas('official_infos', [
            'customer_office_unique_id' => 'MANUAL-CID-101',
            'billing_type' => 'prepaid',
            'connection_type' => 'fiber',
        ]);
    }

    public function test_customer_unique_id_shows_live_availability_without_generating_an_id(): void
    {
        $this->createCustomer('CID-ALREADY-USED', 'active');

        Livewire::test(NewCustomer::class)
            ->assertSee('Use My Location')
            ->assertSee('Static IP Address')
            ->assertSee('Subnet / Prefix')
            ->assertSee('Gateway')
            ->set('customer_unique_id', 'CID-ALREADY-USED')
            ->assertSet('customerUniqueIdAvailability', 'taken')
            ->assertSee('This CID is already in use')
            ->set('customer_unique_id', 'CID-AVAILABLE-2026')
            ->assertSet('customerUniqueIdAvailability', 'available')
            ->assertSee('CID is available');

        $this->assertDatabaseMissing('customers_infos', ['customer_unique_id' => 'CID-AVAILABLE-2026']);
    }

    public function test_static_network_fields_are_saved_and_available_to_customer_edit(): void
    {
        $router = RouterList::create([
            'router_name' => 'static-network-fields-router',
            'ip_address' => '127.0.0.1',
            'username' => 'static-user',
            'password' => 'static-password',
            'action' => 'connected',
        ]);

        $permission = \Spatie\Permission\Models\Permission::findOrCreate('mikrotik-user-create', 'web');
        $this->superAdmin->givePermissionTo($permission);

        $this->partialMock(MikrotikController::class, function ($mock): void {
            $mock->shouldReceive('singleWrite')->once()->andReturn('OK');
        });

        Livewire::test(NewCustomer::class)
            ->set('customer_name', 'Static Network Customer')
            ->set('customer_unique_id', 'STATIC-NETWORK-CID-001')
            ->set('router_name', $router->router_name)
            ->set('service', 'static')
            ->assertSet('service', 'static')
            ->set('interface', 'ether1')
            ->set('queue_name', 'static-cid-001')
            ->set('ip_address', '192.0.2.10')
            ->set('subnet_prefix', '24')
            ->set('gateway', '192.0.2.1')
            ->set('caller_id', '00:11:22:33:44:55')
            ->set('bandwidth', '10M/10M')
            ->set('package_name', 'Free Customer')
            ->call('save')
            ->assertRedirect('/customers');

        $customer = CustomersInfo::where('customer_unique_id', 'STATIC-NETWORK-CID-001')->firstOrFail();
        $this->assertNotNull($customer->pppUser);
        $this->assertDatabaseHas('p_p_p_secrets', [
            'id' => $customer->pppUser->id,
            'service' => 'static',
            'interface' => 'ether1',
            'ip_address' => '192.0.2.10',
            'subnet_prefix' => '24',
            'gateway' => '192.0.2.1',
            'caller_id' => '00:11:22:33:44:55',
        ]);
        $this->assertSame('-', $customer->pppUser->fresh()->password);

        Livewire::test(EditCustomer::class, ['customerId' => encrypt($customer->customer_unique_id)])
            ->assertSet('fields.pppUser.interface', 'ether1')
            ->assertSet('fields.pppUser.ip_address', '192.0.2.10')
            ->assertSet('fields.pppUser.subnet_prefix', '24')
            ->assertSet('fields.pppUser.gateway', '192.0.2.1');
    }

    public function test_edit_customer_saves_customer_and_billing_fields_without_changing_the_manual_id(): void
    {
        $customer = $this->createCustomer('MANUAL-CID-EDIT', 'active');
        OfficialInfo::create([
            'customer_office_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'prepaid',
            'connection_type' => 'fiber',
            'connectivity_type' => 'shared',
            'connected_by' => $this->superAdmin->id,
        ]);

        Livewire::test(EditCustomer::class, ['customerId' => encrypt($customer->customer_unique_id)])
            ->set('fields.customer.customer_name', 'Edited workflow customer')
            ->set('fields.customer.mobile', '')
            ->set('fields.customer.identification_no', 'ID-2')
            ->set('fields.billing.monthly_rent', 750)
            ->set('fields.billing.total_amount', 750)
            ->set('fields.billing.due_amount', 750)
            ->call('saveEditForm')
            ->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'customer_unique_id' => 'MANUAL-CID-EDIT',
            'customer_name' => 'Edited workflow customer',
            'mobile' => null,
            'identification_no' => 'ID-2',
        ]);
        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => 'MANUAL-CID-EDIT',
            'monthly_rent' => 750,
        ]);
    }

    public function test_http_enable_activates_customer_and_queues_router_work_when_router_is_disconnected(): void
    {
        $router = $this->disconnectedRouter('enable-workflow-router');
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'enable-workflow-user',
            'password' => 'test-secret',
            'profile' => 'Home-20M',
            'service' => 'pppoe',
            'status' => 'disable',
        ]);
        $customer = $this->createCustomer('ENABLE-WORKFLOW-CID', 'disable', $ppp->id);

        $this->post(route('customers.enable', encrypt($customer->customer_unique_id)))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('router_sync_pending', true);

        $this->assertDatabaseHas('customers_infos', ['id' => $customer->id, 'status' => 'active', 'disable_count' => 0]);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_summaries', [
            'customer_payment_unique_id' => $customer->customer_unique_id,
        ]);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => $customer->customer_unique_id,
            'router_name' => $router->router_name,
            'username' => $ppp->username,
            'action' => 'enable',
            'status' => 'pending',
        ]);
    }

    public function test_pending_enable_action_reconciles_profile_and_marks_the_queue_item_completed(): void
    {
        $router = RouterList::create([
            'router_name' => 'queue-enable-router',
            'ip_address' => '127.0.0.1',
            'username' => 'queue-user',
            'password' => 'queue-password',
            'action' => 'connected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'queue-enable-user',
            'password' => 'test-secret',
            'profile' => 'Home-20M',
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        $customer = $this->createCustomer('QUEUE-ENABLE-CID', 'active', $ppp->id);
        $pendingId = \DB::table('mikrotik_pending_actions')->insertGetId([
            'customer_unique_id' => $customer->customer_unique_id,
            'router_name' => $router->router_name,
            'username' => $ppp->username,
            'action' => 'enable',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mikrotik = \Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('enablePPPSecret')->once()
            ->with($customer->customer_unique_id, $router->router_name, $ppp->username);
        $mikrotik->shouldReceive('updatePPPSecret')->once()
            ->with($router->router_name, $ppp->username, 'profile', 'Home-20M');
        $mikrotik->shouldReceive('mtQuote')->once()
            ->with($ppp->username)->andReturn('"queue-enable-user"');
        $mikrotik->shouldReceive('singleWrite')->once()
            ->with($router->router_name, '/ppp active remove [find name="queue-enable-user"]')
            ->andReturn('OK');

        (new ReconcileMikrotikPendingAction($pendingId))->handle($mikrotik);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $pendingId,
            'status' => 'completed',
            'attempts' => 1,
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id, 'status' => 'active']);
    }

    public function test_superseded_pending_action_is_not_executed_by_an_already_queued_job(): void
    {
        $pendingId = \DB::table('mikrotik_pending_actions')->insertGetId([
            'customer_unique_id' => 'STALE-WORKFLOW-CID',
            'router_name' => 'router-from-old-action',
            'username' => 'stale-username',
            'action' => 'disable',
            'status' => 'superseded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mikrotik = \Mockery::mock(MikrotikController::class);
        (new ReconcileMikrotikPendingAction($pendingId))->handle($mikrotik);

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'id' => $pendingId,
            'status' => 'superseded',
        ]);
    }

    public function test_livewire_enable_and_disable_cycle_queues_both_router_actions_when_disconnected(): void
    {
        $router = $this->disconnectedRouter('cycle-workflow-router');
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'cycle-workflow-user',
            'password' => 'test-secret',
            'profile' => 'Home-10M',
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        $customer = $this->createCustomer('CYCLE-WORKFLOW-CID', 'active', $ppp->id);

        Livewire::test(CustomerList::class)
            ->call('disableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', ['id' => $customer->id, 'status' => 'disable']);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => $customer->customer_unique_id,
            'action' => 'disable',
            'status' => 'pending',
        ]);

        Livewire::test(CustomerList::class)
            ->call('enableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => $customer->customer_unique_id,
            'action' => 'disable',
            'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('customers_infos', ['id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id, 'status' => 'active']);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => $customer->customer_unique_id,
            'action' => 'enable',
            'status' => 'pending',
        ]);
    }
}
