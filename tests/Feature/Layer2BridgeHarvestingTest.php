<?php

namespace Tests\Feature;

use App\Models\MikrotikBridgeHostEntry;
use App\Models\MikrotikBridgeHostSnapshot;
use App\Models\NetworkBridgeFdbEntry;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Layer2BridgeHarvestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_page_combines_saved_olt_switch_and_mikrotik_entries_without_polling(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Harvest OLT', 'ip_address' => '192.0.2.31', 'status' => 'online', 'is_active' => true,
        ]);
        $switch = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Harvest Switch', 'ip_address' => '192.0.2.32', 'status' => 'online', 'is_active' => true,
        ]);
        $oltSnapshot = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $olt->id, 'sampled_at' => now(), 'source' => 'dot1q', 'status' => 'ok', 'entry_count' => 1,
        ]);
        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $oltSnapshot->id, 'mac_address' => '00:11:22:33:44:01', 'vlan_id' => 101,
            'bridge_port' => 1, 'if_index' => 11, 'interface_name' => 'EPON0/1:1', 'entry_status' => 'learned', 'source' => 'dot1q',
        ]);
        $switchSnapshot = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $switch->id, 'sampled_at' => now(), 'source' => 'dot1q', 'status' => 'ok', 'entry_count' => 1,
        ]);
        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $switchSnapshot->id, 'mac_address' => '00:11:22:33:44:02', 'vlan_id' => 102,
            'bridge_port' => 2, 'if_index' => 12, 'interface_name' => 'GE0/2', 'entry_status' => 'learned', 'source' => 'dot1q',
        ]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $router = RouterList::create([
            'router_name' => 'Harvest MikroTik', 'ip_address' => '192.0.2.33', 'username' => 'readonly',
            'password' => 'test-password', 'api_port' => 8729, 'api_ssl' => true, 'action' => 'connected',
        ]);
        $routerSnapshot = MikrotikBridgeHostSnapshot::create([
            'router_list_id' => $router->id, 'sampled_at' => now(), 'source' => 'routeros-api', 'status' => 'ok', 'entry_count' => 2,
        ]);
        MikrotikBridgeHostEntry::create([
            'snapshot_id' => $routerSnapshot->id, 'mac_address' => '00:11:22:33:44:03', 'vlan_id' => 103,
            'bridge_name' => 'bridge1', 'interface_name' => 'ether3', 'is_dynamic' => true,
        ]);
        MikrotikBridgeHostEntry::create([
            'snapshot_id' => $routerSnapshot->id, 'router_entry_id' => 'pppoe:*4',
            'mac_address' => '00:11:22:33:44:04', 'is_dynamic' => true,
        ]);

        $admin = User::factory()->create(['email_verified_at' => now()]);
        Role::findOrCreate('Super Admin', 'web');
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);
        $response = $this->get(route('layer2-bridge-harvesting'));

        $response->assertOk()
            ->assertSee('Layer-2 OLT Switch Bridge Harvesting')
            ->assertSee('Harvest OLT')
            ->assertSee('Harvest Switch')
            ->assertSee('Harvest MikroTik')
            ->assertSee('00:11:22:33:44:01')
            ->assertSee('00:11:22:33:44:02')
            ->assertSee('00:11:22:33:44:03')
            ->assertSee('00:11:22:33:44:04')
            ->assertSee('MikroTik PPPoE caller-id');
        $this->assertSame(2, NetworkBridgeFdbSnapshot::query()->count());
        $this->assertSame(1, MikrotikBridgeHostSnapshot::query()->count());
    }

    public function test_unified_harvesting_route_is_get_only(): void
    {
        $route = Route::getRoutes()->getByName('layer2-bridge-harvesting');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());
    }
}
