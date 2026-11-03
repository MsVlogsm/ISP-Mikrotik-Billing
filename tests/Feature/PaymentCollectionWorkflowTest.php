<?php

namespace Tests\Feature;

use App\Livewire\PaymentCollection;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentCollectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Dhaka'));
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($admin);
        Event::fake();
    }

    public function test_prepaid_receipt_extends_expiry_and_same_day_retry_does_not_record_twice(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'PAY-CYCLE-001',
            'customer_name' => 'Payment Cycle Test',
            'connection_date' => '2026-10-03',
            'status' => 'active',
            'disable_count' => 0,
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
            'auto_disable' => true,
            'auto_disable_date' => '2026-11-03',
            'auto_disable_month' => 0,
        ]);

        $component = Livewire::test(PaymentCollection::class)
            ->call('selectCustomer', encrypt($customer->customer_unique_id))
            ->set('paid_amount', 500)
            ->set('payment_method', 'cash')
            ->set('collection_date', now()->format('Y-m-d\\TH:i'))
            ->set('send_sms', false)
            ->call('calculatePayment')
            ->assertSet('expire_date', '2026-12-03')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'paid_amount' => 500,
            'due_amount' => 0,
            'auto_disable_date' => '2026-12-03',
        ]);
        $this->assertDatabaseCount('collection_summaries', 1);

        $component
            ->call('selectCustomer', encrypt($customer->customer_unique_id))
            ->set('paid_amount', 500)
            ->set('payment_method', 'cash')
            ->set('collection_date', now()->format('Y-m-d\\TH:i'))
            ->set('send_sms', false)
            ->call('savePayment')
            ->assertHasErrors('payment_details');

        $this->assertDatabaseCount('collection_summaries', 1);
        $this->assertDatabaseHas('billing_infos', [
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'paid_amount' => 500,
            'due_amount' => 0,
            'auto_disable_date' => '2026-12-03',
        ]);
    }
}
