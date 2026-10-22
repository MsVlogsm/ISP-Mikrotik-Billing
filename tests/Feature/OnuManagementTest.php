<?php

namespace Tests\Feature;

use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OnuManagementTest extends TestCase
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

    public function test_onu_management_page_renders_with_empty_inventory(): void
    {
        $this->get(route('network-inventory.onu-management'))
            ->assertOk()
            ->assertSee('ONU Management')
            ->assertSee('No saved ONU mappings found')
            ->assertSee('Sync ONU Inventory');
    }

    public function test_olt_and_onu_pages_show_saved_mapping_data(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Test EPON OLT',
            'vendor' => 'BDCOM',
            'model' => 'P3608',
            'status' => 'online',
            'health_status' => 'online',
            'pon_ports' => 8,
        ]);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-17',
            'onu_mac' => 'AA:BB:CC:DD:EE:FF',
            'onu_serial' => 'TESTSERIAL17',
            'pon_port' => '1',
            'status' => 'online',
            'rx_power' => -18.50,
            'tx_power' => 2.10,
            'opm_last_seen_at' => now(),
        ]);

        $this->get(route('network-inventory.olt'))
            ->assertOk()
            ->assertSee('FTTH OLT Devices')
            ->assertSee('Test EPON OLT')
            ->assertSee('ONU Total');

        $this->get(route('network-inventory.onu-management'))
            ->assertOk()
            ->assertSee('Test EPON OLT')
            ->assertSee('ONU-17')
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('-18.50 dBm');
    }

    public function test_network_monitoring_page_and_export_render(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Monitoring Test OLT',
            'vendor' => 'BDCOM',
            'status' => 'online',
            'health_status' => 'online',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => 'MON-ONU-1',
            'onu_mac' => 'AA:BB:CC:11:22:33',
            'pon_port' => 'EPON0/1',
            'status' => 'online',
            'rx_power' => -18.75,
            'last_seen_at' => now(),
        ]);

        $this->get(route('network-monitoring'))
            ->assertOk()
            ->assertSee('Customer Network Monitoring')
            ->assertSee('MON-ONU-1')
            ->assertSee('EPON0/1')
            ->assertSee('-18.75 dBm');

        $this->get(route('network-monitoring.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('MON-ONU-1');
    }

    public function test_onu_management_csv_export_has_expected_headers(): void
    {
        $response = $this->get(route('network-inventory.onu-management.export'));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="xlink-onu-management.csv"');

        $this->assertStringContainsString('OLT,"Customer ID","Customer Name","PPPoE User","PON Port","ONU ID","ONU MAC","ONU Serial",Status', $response->getContent());
    }
}
