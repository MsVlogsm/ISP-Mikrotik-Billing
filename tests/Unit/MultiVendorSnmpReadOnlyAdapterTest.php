<?php

namespace Tests\Unit;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\Adapters\MultiVendorSnmpReadOnlyAdapter;
use Tests\TestCase;

class MultiVendorSnmpReadOnlyAdapterTest extends TestCase
{
    public function test_supported_vendor_profiles_are_selected(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        foreach (['BDCOM', 'Huawei', 'VSOL', 'HSGQ', 'PHOTON', 'C-DATA', 'ZTE', 'FiberHome', 'Nokia', 'Generic'] as $vendor) {
            $device = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => $vendor]);
            $this->assertTrue($adapter->supports($device), $vendor.' profile is not registered');
        }
    }

    public function test_vsol_v1600d4_live_sysdescr_uses_verified_auth_info_table_columns(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $device = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => 'VSOL', 'model' => 'V1601E04-DP', 'firmware' => 'V1.0.2R']);
        $method = new \ReflectionMethod(MultiVendorSnmpReadOnlyAdapter::class, 'profile');
        $method->setAccessible(true);
        $profile = $method->invoke($adapter, $device, 'V1600D4');

        $this->assertSame('vsol_v1600d4_auth_info_table', $profile['key']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.3', $profile['onu_oids']['onu_id']);
        $this->assertArrayNotHasKey('tx_power', $profile['onu_oids']);
    }

    public function test_family_match_without_exact_model_keeps_private_oids_disabled(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $method = new \ReflectionMethod(MultiVendorSnmpReadOnlyAdapter::class, 'profile');

        foreach (['Huawei', 'C-DATA', 'ZTE', 'FiberHome', 'Nokia'] as $vendor) {
            $device = new NetworkInventoryDevice([
                'type' => 'olt',
                'vendor' => $vendor,
                'model' => 'Unknown '.$vendor.' OLT',
            ]);
            $profile = $method->invoke($adapter, $device);

            $this->assertNotNull($profile, $vendor.' family profile should remain available for standard IF-MIB.');
            $this->assertSame([], $profile['onu_oids'], $vendor.' private ONU MIB must not be guessed from family alone.');
            $this->assertNull($profile['enterprise_oid']);
            $this->assertSame(['sysDescr', 'sysUpTime'], array_keys($profile['oids']));
        }
    }

    public function test_bdcom_private_mib_requires_the_exact_p3616_2te_model(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $method = new \ReflectionMethod(MultiVendorSnmpReadOnlyAdapter::class, 'profile');

        $known = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => 'BDCOM', 'model' => 'P3616-2TE']);
        $unknown = new NetworkInventoryDevice(['type' => 'olt', 'vendor' => 'BDCOM', 'model' => 'BDCOM EPON OLT']);

        $knownProfile = $method->invoke($adapter, $known);
        $unknownProfile = $method->invoke($adapter, $unknown);

        $this->assertArrayHasKey('rx_power', $knownProfile['onu_oids']);
        $this->assertSame([], $unknownProfile['onu_oids']);
        $this->assertNull($unknownProfile['enterprise_oid']);
    }

    public function test_vsol_private_mib_requires_the_verified_firmware(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $method = new \ReflectionMethod(MultiVendorSnmpReadOnlyAdapter::class, 'profile');

        $device = new NetworkInventoryDevice([
            'type' => 'olt',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'UNKNOWN',
        ]);

        $profile = $method->invoke($adapter, $device);

        $this->assertSame([], $profile['onu_oids']);
        $this->assertNull($profile['enterprise_oid']);
    }

    public function test_non_olt_devices_are_not_supported(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $this->assertFalse($adapter->supports(new NetworkInventoryDevice(['type' => 'router', 'vendor' => 'Huawei'])));
    }

    public function test_missing_host_or_community_is_reported_without_network_write(): void
    {
        $adapter = new MultiVendorSnmpReadOnlyAdapter;
        $device = new NetworkInventoryDevice(['id' => 99, 'type' => 'olt', 'vendor' => 'Huawei']);
        $result = $adapter->read($device);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['meta']['read_only']);
        $this->assertFalse($result['meta']['writes_performed']);
    }
}
