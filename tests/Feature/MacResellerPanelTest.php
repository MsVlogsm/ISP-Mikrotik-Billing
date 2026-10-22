<?php

namespace Tests\Feature;

use App\Livewire\Reseller\ResellerCustomerList;
use App\Models\CustomersInfo;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MacResellerPanelTest extends TestCase
{
    use RefreshDatabase;

    private function createMacReseller(string $panelType = 'mac'): array
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Reseller', 'guard_name' => 'web']);
        $user->assignRole($role);

        $reseller = Reseller::create([
            'user_id' => $user->id,
            'company' => 'Test MAC Reseller',
            'commission_percentage' => 0,
            'balance' => 0,
            'status' => 'active',
            'panel_type' => $panelType,
        ]);

        return [$user, $reseller];
    }

    private function makeCustomer(string $id, int $resellerId, string $status = 'pending'): CustomersInfo
    {
        return CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Client '.$id,
            'mobile' => null,
            'status' => $status,
            'reseller_id' => $resellerId,
            'disable_count' => 0,
        ]);
    }

    public function test_mac_reseller_has_an_independent_dashboard_and_client_routes(): void
    {
        [$user] = $this->createMacReseller();

        $this->actingAs($user)
            ->get('/mac-reseller/dashboard')
            ->assertOk()
            ->assertSee('MAC Reseller Dashboard');

        $this->actingAs($user)
            ->get('/customers')
            ->assertRedirect(route('mac-reseller.dashboard'));

        $this->actingAs($user)
            ->get('/mikrotik-server')
            ->assertRedirect(route('mac-reseller.dashboard'));

        $this->actingAs($user)
            ->get('/reseller/dashboard')
            ->assertRedirect(route('mac-reseller.dashboard'));
    }

    public function test_super_admin_can_choose_and_enter_any_mac_reseller_portal(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->assignRole($adminRole);
        [, $reseller] = $this->createMacReseller();

        $this->actingAs($admin)
            ->get('/mac-reseller/dashboard')
            ->assertRedirect(route('mac-reseller.choose'));

        $this->actingAs($admin)
            ->get('/mac-reseller/choose')
            ->assertOk()
            ->assertSee('Select a MAC Reseller Portal')
            ->assertSee('Test MAC Reseller');

        $this->actingAs($admin)
            ->post('/mac-reseller/select', ['reseller_id' => $reseller->id])
            ->assertRedirect(route('mac-reseller.dashboard'));

        $this->assertSame($reseller->id, (int) session('mac_reseller_context_id'));
        $this->actingAs($admin)
            ->get('/mac-reseller/dashboard')
            ->assertOk()
            ->assertSee('MAC Reseller Dashboard');

        $this->actingAs($admin)
            ->post('/mac-reseller/exit')
            ->assertRedirect(route('mac-reseller.choose'));
        $this->assertNull(session('mac_reseller_context_id'));
    }

    public function test_super_admin_mac_portal_context_keeps_client_actions_scoped_to_selected_reseller(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->assignRole($adminRole);
        [, $selectedReseller] = $this->createMacReseller();
        [, $otherReseller] = $this->createMacReseller();
        $own = $this->makeCustomer('MAC-ADMIN-OWN-001', $selectedReseller->id, 'pending');
        $other = $this->makeCustomer('MAC-ADMIN-OTHER-001', $otherReseller->id, 'pending');

        $this->actingAs($admin)->withSession(['mac_reseller_context_id' => $selectedReseller->id]);
        Livewire::actingAs($admin)
            ->test(ResellerCustomerList::class)
            ->call('disableCustomer', encrypt($other->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $other->id,
            'reseller_id' => $otherReseller->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->withSession(['mac_reseller_context_id' => $selectedReseller->id]);
        Livewire::actingAs($admin)
            ->test(ResellerCustomerList::class)
            ->call('disableCustomer', encrypt($own->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $own->id,
            'reseller_id' => $selectedReseller->id,
            'status' => 'disable',
        ]);
    }

    public function test_standard_reseller_cannot_enter_the_mac_panel(): void
    {
        [$user] = $this->createMacReseller('standard');

        $this->actingAs($user)
            ->get('/mac-reseller/dashboard')
            ->assertForbidden();
    }

    public function test_mac_reseller_cannot_disable_or_delete_another_resellers_client(): void
    {
        [$macUser, $macReseller] = $this->createMacReseller();
        [, $otherReseller] = $this->createMacReseller();

        $own = $this->makeCustomer('MAC-OWN-001', $macReseller->id, 'pending');
        $other = $this->makeCustomer('MAC-OTHER-001', $otherReseller->id, 'disable');

        Livewire::actingAs($macUser)
            ->test(ResellerCustomerList::class)
            ->call('disableCustomer', encrypt($other->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $other->id,
            'reseller_id' => $otherReseller->id,
            'status' => 'disable',
        ]);

        Livewire::actingAs($macUser)
            ->test(ResellerCustomerList::class)
            ->call('deleteCustomer', encrypt($other->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $other->id,
            'reseller_id' => $otherReseller->id,
        ]);
    }

    public function test_mac_reseller_can_disable_and_remove_its_own_client(): void
    {
        [$user, $reseller] = $this->createMacReseller();
        $client = $this->makeCustomer('MAC-OWN-002', $reseller->id, 'pending');

        Livewire::actingAs($user)
            ->test(ResellerCustomerList::class)
            ->call('disableCustomer', encrypt($client->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $client->id,
            'reseller_id' => $reseller->id,
            'status' => 'disable',
        ]);

        Livewire::actingAs($user)
            ->test(ResellerCustomerList::class)
            ->call('deleteCustomer', encrypt($client->customer_unique_id));

        $this->assertSoftDeleted('customers_infos', ['id' => $client->id]);
    }
}
