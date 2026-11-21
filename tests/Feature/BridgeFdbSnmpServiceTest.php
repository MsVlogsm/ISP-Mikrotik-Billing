<?php

namespace Tests\Feature;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\BridgeFdbSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BridgeFdbSnmpServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vlan_fdb_mapping_uses_the_final_vlan_index_from_time_mark_and_vlan_id(): void
    {
        $service = app(BridgeFdbSnmpService::class);
        $method = new ReflectionMethod($service, 'vlanIdsByFdb');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            '.1.3.6.1.2.1.17.7.1.4.2.1.3.0.100' => 'INTEGER: 500',
            '.1.3.6.1.2.1.17.7.1.4.2.1.3.0.200' => 'INTEGER: 500',
            '.1.3.6.1.2.1.17.7.1.4.2.1.3.0.300' => 'INTEGER: 600',
        ]);

        $this->assertSame([
            500 => [100, 200],
            600 => [300],
        ], $result);
    }

    public function test_poll_without_a_read_only_community_records_an_error_without_network_writes(): void
    {
        config(['bridge_fdb.live_poll_enabled' => true]);

        $device = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'FDB OLT without SNMP credentials',
            'ip_address' => '192.0.2.50',
            'snmp_port' => 161,
            'snmp_version' => '2C',
            'status' => 'unknown',
            'is_active' => true,
        ]);

        $snapshot = app(BridgeFdbSnmpService::class)->poll($device);

        $this->assertSame('error', $snapshot->status);
        $this->assertStringContainsString('SNMP community is not configured', $snapshot->error_message);
        $this->assertSame(true, $snapshot->meta['read_only']);
        $this->assertSame(false, $snapshot->meta['writes_performed']);
        $this->assertSame(0, $snapshot->entry_count);
        $this->assertDatabaseHas('network_bridge_fdb_snapshots', [
            'id' => $snapshot->id,
            'network_inventory_device_id' => $device->id,
            'status' => 'error',
        ]);
    }

    public function test_poll_rejects_snmp_v3_until_credentials_and_authentication_are_supported(): void
    {
        config(['bridge_fdb.live_poll_enabled' => true]);

        $device = NetworkInventoryDevice::create([
            'type' => 'switch',
            'name' => 'SNMPv3 switch',
            'ip_address' => '192.0.2.60',
            'snmp_port' => 161,
            'snmp_version' => '3',
            'snmp_community' => 'not-used-for-v3',
            'status' => 'unknown',
            'is_active' => true,
        ]);

        $snapshot = app(BridgeFdbSnmpService::class)->poll($device);

        $this->assertSame('error', $snapshot->status);
        $this->assertStringContainsString('SNMP v1/v2c only', $snapshot->error_message);
        $this->assertSame(false, $snapshot->meta['writes_performed']);
    }
}
