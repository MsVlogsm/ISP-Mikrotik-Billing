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

    public function test_vsol_cmac_table_joins_exact_mac_vlan_type_and_port_columns(): void
    {
        $service = app(BridgeFdbSnmpService::class);
        $method = new ReflectionMethod($service, 'normalizeVsolCmacRows');
        $method->setAccessible(true);

        $result = $method->invoke($service,
            [
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.2.1' => 'INTEGER: 3362',
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.2.2' => 'INTEGER: 4095',
            ],
            [
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.3.1' => 'Hex-STRING: AA BB CC DD EE 11',
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.3.2' => 'Hex-STRING: AA BB CC DD EE 12',
            ],
            [
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.4.1' => 'INTEGER: 1',
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.4.2' => 'INTEGER: 0',
            ],
            [
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.5.1' => 'STRING: "EPON0/1:12"',
                '.1.3.6.1.4.1.37950.1.1.5.10.3.2.1.5.2' => 'STRING: "EPON0/1:13"',
            ]
        );

        $this->assertCount(1, $result);
        $this->assertSame([
            'mac_address' => 'aa:bb:cc:dd:ee:11',
            'vlan_id' => 3362,
            'fdb_id' => null,
            'bridge_port' => null,
            'if_index' => null,
            'interface_name' => 'EPON0/1:12',
            'entry_status' => 'learned',
            'status_code' => null,
            'source' => 'vsol-cmac',
        ], $result[0]);
    }

    public function test_indexed_integer_values_preserve_composite_fdb_indexes_and_numeric_bridge_port_indexes(): void
    {
        $service = app(BridgeFdbSnmpService::class);
        $method = new ReflectionMethod($service, 'indexedIntegers');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            '.1.3.6.1.2.1.17.7.1.2.2.1.3.100.6.0.17.34.51.68.85' => 'INTEGER: 3',
            '.1.3.6.1.2.1.17.1.4.1.2.7' => 'INTEGER: 42',
        ], '1.3.6.1.2.1.17.7.1.2.2.1.3');

        $this->assertSame(3, $result['100.6.0.17.34.51.68.85']);
        $this->assertArrayNotHasKey(100, $result);

        $bridgePorts = $method->invoke($service, [
            '.1.3.6.1.2.1.17.1.4.1.2.7' => 'INTEGER: 42',
        ], '1.3.6.1.2.1.17.1.4.1.2');

        $this->assertSame(42, $bridgePorts[7]);
    }

    public function test_interface_index_uses_confirmed_direct_if_mib_index_when_bridge_mapping_is_missing(): void
    {
        $service = app(BridgeFdbSnmpService::class);
        $method = new ReflectionMethod($service, 'resolveInterfaceIndex');

        $this->assertSame(101, $method->invoke($service, 1, [1 => 101], [126 => 'EPON0/4:12'], [101 => 'GE0/1']));
        $this->assertSame(126, $method->invoke($service, 126, [1 => 101], [126 => 'EPON0/4:12'], []));
        $this->assertNull($method->invoke($service, 999, [1 => 101], [], []));
    }

    public function test_indexed_interface_values_remove_snmp_type_prefix_and_quotes(): void
    {
        $service = app(BridgeFdbSnmpService::class);
        $method = new ReflectionMethod($service, 'indexedValues');

        $result = $method->invoke($service, [
            '.1.3.6.1.2.1.31.1.1.1.1.126' => 'STRING: "EPON0/4:12"',
            '.1.3.6.1.2.1.31.1.1.1.1.1' => 'STRING: "GE0/1"',
        ], '1.3.6.1.2.1.31.1.1.1.1');

        $this->assertSame([
            126 => 'EPON0/4:12',
            1 => 'GE0/1',
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
