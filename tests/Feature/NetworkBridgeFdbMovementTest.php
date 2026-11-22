<?php

namespace Tests\Feature;

use App\Models\NetworkBridgeFdbEntry;
use App\Models\NetworkBridgeFdbMovement;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Services\Olt\BridgeFdbOidParser;
use App\Services\Olt\BridgeFdbSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class NetworkBridgeFdbMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_exact_mac_vlan_interface_movement_between_successful_snapshots(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Movement OLT',
            'ip_address' => '192.0.2.90',
            'status' => 'online',
            'is_active' => true,
        ]);

        $previous = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $device->id,
            'sampled_at' => now()->subMinutes(5),
            'source' => 'dot1q',
            'status' => 'ok',
            'entry_count' => 1,
            'learned_count' => 1,
        ]);
        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $previous->id,
            'mac_address' => '00:11:22:33:44:55',
            'vlan_id' => 100,
            'bridge_port' => 1,
            'if_index' => 10,
            'interface_name' => 'EPON0/1:1',
            'entry_status' => 'learned',
            'status_code' => 3,
            'source' => 'dot1q',
        ]);

        $current = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $device->id,
            'sampled_at' => now(),
            'source' => 'dot1q',
            'status' => 'ok',
            'entry_count' => 1,
            'learned_count' => 1,
        ]);
        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $current->id,
            'mac_address' => '00:11:22:33:44:55',
            'vlan_id' => 100,
            'bridge_port' => 2,
            'if_index' => 20,
            'interface_name' => 'EPON0/2:1',
            'entry_status' => 'learned',
            'status_code' => 3,
            'source' => 'dot1q',
        ]);

        $service = new BridgeFdbSnmpService(new BridgeFdbOidParser);
        $method = new ReflectionMethod($service, 'recordMovements');
        $method->invoke($service, $device, $previous, $current);

        $movement = NetworkBridgeFdbMovement::query()->sole();
        $this->assertSame('00:11:22:33:44:55', $movement->mac_address);
        $this->assertSame(100, $movement->vlan_id);
        $this->assertSame('EPON0/1:1', $movement->from_interface);
        $this->assertSame('EPON0/2:1', $movement->to_interface);
        $this->assertSame(1, $movement->moves_in_10m);
        $this->assertFalse($movement->is_flapping);
    }
}
