<?php

namespace Tests\Feature;

use App\Livewire\CustomerList;
use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function disconnectedRouter(string $name = 'test-router'): RouterList
    {
        return RouterList::create([
            'router_name' => $name,
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'disconnected',
        ]);
    }

    private function customerWithPpp(string $id, string $routerName = 'test-router', string $status = 'active'): array
    {
        $router = $this->disconnectedRouter($routerName);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'ppp-'.$id,
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Test '.$id,
            'status' => $status,
            'disable_count' => 0,
            'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $ppp];
    }

    public function test_cash_credit_action_page_renders_for_an_authorized_customer(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ACTION-CASH-PAGE',
            'customer_name' => 'Cash Page Test',
            'status' => 'active',
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        $this->get(route('customer.actions', [
            'id' => encrypt($customer->customer_unique_id),
            'action' => 'cash-credit',
        ]))
            ->assertOk()
            ->assertSee('Cash / Credit / Return')
            ->assertSee('Transaction Type')
            ->assertSee('Receipt No.');
    }

    public function test_cash_credit_records_a_new_receipt_and_updates_billing(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ACTION-CASH-UNIQUE',
            'customer_name' => 'Cash Unique Receipt Test',
            'status' => 'active',
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        $response = $this->post(route('customer.actions.handle', [
            'id' => encrypt($customer->customer_unique_id),
            'action' => 'cash-credit',
        ]), [
            'type' => 'cash_received',
            'amount' => 125,
            'payment_method' => 'cash',
            'receipt_no' => 'CASH-RECEIPT-NEW-001',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('collection_summaries', [
            'customer_collection_unique_id' => $customer->customer_unique_id,
            'collection_amount' => 125,
            'transaction_id' => 'CASH-RECEIPT-NEW-001',
            'payment_type' => 'cash_received',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'paid_amount' => 125,
            'due_amount' => 375,
        ]);
    }

    public function test_cash_credit_rejects_a_receipt_number_already_used_by_another_payment_without_mutating_billing(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ACTION-CASH-DUPLICATE',
            'customer_name' => 'Cash Duplicate Receipt Test',
            'status' => 'active',
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
        ]);

        CollectionSummary::create([
            'customer_collection_unique_id' => 'OTHER-CUSTOMER',
            'collection_date' => now(),
            'collection_amount' => 100,
            'collected_by' => 'Test',
            'payment_type' => 'monthly_recharge',
            'payment_method' => 'cash',
            'transaction_id' => 'CASH-RECEIPT-DUP-001',
            'payment_status' => 'paid',
            'invoice_no' => 999994,
        ]);

        $response = $this->post(route('customer.actions.handle', [
            'id' => encrypt($customer->customer_unique_id),
            'action' => 'cash-credit',
        ]), [
            'type' => 'cash_received',
            'amount' => 100,
            'payment_method' => 'cash',
            'receipt_no' => 'CASH-RECEIPT-DUP-001',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['receipt_no']);

        $this->assertDatabaseCount('collection_summaries', 1);
        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'paid_amount' => 0,
            'due_amount' => 500,
        ]);
    }

    public function test_disconnected_router_disable_updates_local_state_and_queues_remote_action(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-DISABLE');

        Livewire::test(CustomerList::class)
            ->call('disableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'disable',
        ]);
        $this->assertDatabaseHas('p_p_p_secrets', [
            'id' => $ppp->id,
            'status' => 'disable',
        ]);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-DISABLE',
            'router_name' => 'test-router',
            'username' => 'ppp-ACTION-DISABLE',
            'action' => 'disable',
            'status' => 'pending',
        ]);
    }

    public function test_disconnected_router_delete_removes_local_records_and_queues_remote_removal(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-DELETE', 'delete-router', 'disable');

        Livewire::test(CustomerList::class)
            ->call('deleteCustomer', encrypt($customer->customer_unique_id));

        $this->assertSoftDeleted('customers_infos', ['id' => $customer->id]);
        $this->assertDatabaseMissing('p_p_p_secrets', ['id' => $ppp->id]);
        // Deleted customer references are deliberately archived so the human-readable
        // CID can be reused without attaching old history to a new customer.
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => '__ARCHIVED_CUSTOMER_'.$customer->id,
            'router_name' => 'delete-router',
            'username' => 'ppp-ACTION-DELETE',
            'action' => 'remove',
            'status' => 'pending',
        ]);
    }

    public function test_active_customer_cannot_be_deleted(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-ACTIVE');

        Livewire::test(CustomerList::class)
            ->call('deleteCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'active',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id]);
        $this->assertDatabaseMissing('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-ACTIVE',
            'action' => 'remove',
        ]);
    }

    public function test_pending_action_is_transactional_with_disable(): void
    {
        [$customer] = $this->customerWithPpp('ACTION-ROLLBACK');

        CustomersInfo::saving(function (CustomersInfo $model) use ($customer): void {
            if ($model->is($customer)) {
                throw new \RuntimeException('forced test rollback');
            }
        });

        Livewire::test(CustomerList::class)
            ->call('disableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-ROLLBACK',
            'action' => 'disable',
        ]);
    }
}
