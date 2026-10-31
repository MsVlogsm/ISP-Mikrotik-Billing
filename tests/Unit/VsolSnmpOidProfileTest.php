<?php

namespace Tests\Unit;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\Adapters\VsolSnmpReadOnlyAdapter;
use Tests\TestCase;

class VsolSnmpOidProfileTest extends TestCase
{
    public function test_v1601e_profile_uses_composite_auth_info2_table_and_not_ordinal_onu_list(): void
    {
        $profile = config('olt_vsol.snmp_profiles.v1601e_epon_authinfo2');

        $this->assertNotNull($profile);
        $this->assertContains('V1601E04-DP', $profile['models']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.25', $profile['mib_table_oid']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.25.1.1', $profile['onu_oids']['pon_port']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.25.1.2', $profile['onu_oids']['onu_id']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.25.1.5', $profile['onu_oids']['onu_mac']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6', $profile['opm_oids']['tx_power']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7', $profile['opm_oids']['rx_power']);
        $this->assertArrayNotHasKey('serial', $profile['onu_oids']);
    }

    public function test_live_sysdescr_profile_overrides_a_conflicting_inventory_model(): void
    {
        $adapter = new VsolSnmpReadOnlyAdapter();
        $device = new NetworkInventoryDevice([
            'type' => 'olt',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'adapter_config' => ['transport' => 'snmp'],
        ]);

        $method = new \ReflectionMethod(VsolSnmpReadOnlyAdapter::class, 'mibProfile');
        $method->setAccessible(true);
        $profile = $method->invoke($adapter, $device, [], 'V1600D4');

        $this->assertSame('snmp_sysdescr', $profile['selection_source']);
        $this->assertSame('v1600d4_auth_info_table', $profile['key']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12', $profile['mib_table_oid']);
    }

    public function test_v1600d4_profile_uses_live_verified_auth_info_columns_and_opm_candidates(): void
    {
        $profile = config('olt_vsol.snmp_profiles.v1600d4_auth_info_table');
        $this->assertNotNull($profile);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.2', $profile['onu_oids']['pon_port']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.3', $profile['onu_oids']['onu_id']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.5', $profile['onu_oids']['status']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.1.12.1.6', $profile['onu_oids']['onu_mac']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6', $profile['opm_oids']['tx_power']);
        $this->assertSame('1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7', $profile['opm_oids']['rx_power']);
        $this->assertFalse($profile['client_mac_discovery']);
        $this->assertContains('V1600D4', $profile['models']);
    }

    public function test_vsol_read_only_adapter_supports_vsol_olt_without_any_write_surface(): void
    {
        $adapter = new VsolSnmpReadOnlyAdapter();
        $device = new NetworkInventoryDevice([
            'type' => 'olt',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'adapter_config' => ['transport' => 'snmp'],
        ]);

        $this->assertSame('vsol_snmp_readonly', $adapter->key());
        $this->assertTrue($adapter->supports($device));
    }

    public function test_opm_rows_use_composite_pon_onu_index_not_legacy_row_ordinal(): void
    {
        $adapter = new VsolSnmpReadOnlyAdapter();
        $method = new \ReflectionMethod(VsolSnmpReadOnlyAdapter::class, 'opmIndexForOnu');
        $method->setAccessible(true);

        $this->assertSame('1.3', $method->invoke($adapter, ['pon_port' => '1', 'onu_id' => '3', 'snmp_index' => '22']));
        $this->assertSame('1.51', $method->invoke($adapter, ['pon_port' => 'EPON0/1', 'onu_id' => '51', 'snmp_index' => '6']));
        $this->assertNull($method->invoke($adapter, ['pon_port' => '1', 'onu_id' => '', 'snmp_index' => '22']));
    }

    public function test_vsol_profile_does_not_assign_unverified_oids_to_other_models(): void
    {
        $profiles = config('olt_vsol.snmp_profiles');

        $this->assertContains('V1601E04-DP', $profiles['v1601e_epon_authinfo2']['models']);
        $this->assertContains('V1600D', $profiles['v1600d_epon_authinfo2']['models']);
        $this->assertContains('V1600D4', $profiles['v1600d4_auth_info_table']['models']);
        $this->assertNotContains('V1600D4', $profiles['v1600d_epon_authinfo2']['models']);
        $this->assertNotContains('V1601E04-DP', $profiles['v1600d_epon_authinfo2']['models']);
    }
}
