<?php

namespace Tests\Unit;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\Adapters\MultiVendorSnmpReadOnlyAdapter;
use Tests\TestCase;

class MultiVendorSnmpReadOnlyAdapterTest extends TestCase
{
    public function test_supported_vendor_profiles_are_selected(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter();
        foreach (['BDCOM', 'Huawei', 'VSOL', 'HSGQ', 'PHOTON', 'C-DATA', 'ZTE', 'FiberHome', 'Nokia', 'Generic'] as $vendor) {
            $device = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => $vendor]);
            $this->assertTrue($adapter->supports($device), $vendor . ' profile is not registered');
        }
    }

    public function test_vsol_v1600d4_live_sysdescr_uses_verified_auth_info_table_columns(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter();
        $device = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => 'VSOL', 'model' => 'V1601E04-DP']);
        $method = new \ReflectionMethod(MultiVendorSnmpReadOnlyAdapter::class, 'profile');
        $method->setAccessible(true);
        $profile = $method->invoke($adapter, $device, 'V1600D4');

        $this->assertSame('vsol_v1600d4_auth_info_table', $profile['key']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.3', $profile['onu_oids']['onu_id']);
        $this->assertArrayNotHasKey('tx_power', $profile['onu_oids']);
    }

    public function test_non_olt_devices_are_not_supported(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter();
        $this->assertFalse($adapter->supports(new NetworkInventoryDevice(['type' => 'router', 'vendor' => 'Huawei'])));
    }

    public function test_missing_host_or_community_is_reported_without_network_write(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter();
        $device = new NetworkInventoryDevice(['id' => 99, 'type' => 'olt', 'vendor' => 'Huawei']);
        $result = $adapter->read($device);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['meta']['read_only']);
        $this->assertFalse($result['meta']['writes_performed']);
    }
}
