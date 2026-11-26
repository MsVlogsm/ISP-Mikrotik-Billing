<?php

namespace Tests\Feature;

use App\Jobs\SyncCustomerOnu;
use App\Livewire\CustomerList;
use App\Models\CustomersInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerOnuAsyncSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_customer_onu_sync_is_queued_without_running_olt_poll_in_livewire_request(): void
    {
        Queue::fake();

        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ONU-ASYNC-001',
            'customer_name' => 'Async ONU Test',
            'status' => 'active',
        ]);

        Livewire::test(CustomerList::class)
            ->call('syncCustomerOnu', encrypt($customer->customer_unique_id))
            ->assertDispatched('customer-onu-sync-queued');

        Queue::assertPushed(SyncCustomerOnu::class, function (SyncCustomerOnu $job) use ($customer): bool {
            return $job->customerId === $customer->id
                && $job->userId === auth()->id()
                && $job->lockOwner !== '';
        });

        $status = Cache::get(SyncCustomerOnu::statusKey((int) auth()->id(), (int) $customer->id));
        $this->assertSame('queued', $status['status'] ?? null);
    }

    public function test_customer_onu_sync_status_is_returned_only_to_the_requesting_user_and_cleared_after_completion(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ONU-ASYNC-002',
            'customer_name' => 'Async ONU Status Test',
            'status' => 'active',
        ]);
        $statusKey = SyncCustomerOnu::statusKey((int) auth()->id(), (int) $customer->id);

        Cache::put($statusKey, [
            'status' => 'completed',
            'level' => 'success',
            'message' => 'ONU sync completed.',
        ], now()->addMinutes(15));

        Livewire::test(CustomerList::class)
            ->call('checkCustomerOnuSync', encrypt($customer->customer_unique_id))
            ->assertDispatched('customer-onu-sync-complete');

        $this->assertNull(Cache::get($statusKey));
    }
}
