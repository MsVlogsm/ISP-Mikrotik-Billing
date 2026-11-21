<?php

namespace Tests\Unit;

use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\OltLiveMetricSnapshot;
use App\Models\OltMonitoringSnapshot;
use App\Services\Olt\OltCapabilityRegistry;
use Tests\TestCase;

class OltCapabilityRegistryTest extends TestCase
{
    public function test_it_marks_only_the_exact_vsol_model_and_firmware_profile_as_verified(): void
    {
        $device = $this->device([
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'V1.0.2R',
        ]);

        $matrix = (new OltCapabilityRegistry)->forDevice($device);

        $this->assertSame('verified', $this->capability($matrix, 'model_profile')['status']);
        $this->assertSame('verified', $this->capability($matrix, 'onu_identity')['status']);
        $this->assertSame('not_configured', $this->capability($matrix, 'sfp_ddm')['status']);
        $this->assertSame('disabled', $this->capability($matrix, 'telnet')['status']);
    }

    public function test_a_family_match_without_the_verified_firmware_does_not_claim_verified_onu_support(): void
    {
        $device = $this->device([
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'V1.0.3',
        ]);

        $matrix = (new OltCapabilityRegistry)->forDevice($device);

        $this->assertSame('profile_available', $matrix['profile_status']);
        $this->assertSame('requires_model_mib', $this->capability($matrix, 'onu_identity')['status']);
    }

    public function test_it_uses_live_snapshots_and_explicit_device_oids_for_capabilities(): void
    {
        $device = $this->device([
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'V1.0.2R',
            'adapter_config' => json_encode([
                'sfp_ddm_oids' => [
                    'tx_power_dbm' => ['oid' => '1.3.6.1.4.1.99999.1.1', 'divisor' => 100],
                ],
                'hardware_oids' => [
                    'cpu_percent' => '1.3.6.1.4.1.99999.2.1',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $fdb = new NetworkBridgeFdbSnapshot;
        $fdb->forceFill([
            'status' => 'ok',
            'source' => 'dot1q',
            'entry_count' => 108,
            'learned_count' => 108,
        ]);

        $metrics = new OltLiveMetricSnapshot;
        $metrics->forceFill([
            'interface_count' => 16,
            'cpu_percent' => null,
            'memory_percent' => null,
            'chassis_temp_c' => null,
        ]);

        $monitoring = new OltMonitoringSnapshot;
        $monitoring->forceFill(['rx_sample_count' => 12]);

        $matrix = (new OltCapabilityRegistry)->forDevice($device, $fdb, $metrics, $monitoring, 2);

        $this->assertSame('verified', $this->capability($matrix, 'bridge_fdb')['status']);
        $this->assertSame('verified', $this->capability($matrix, 'standard_snmp')['status']);
        $this->assertSame('verified', $this->capability($matrix, 'opm')['status']);
        $this->assertSame('verified', $this->capability($matrix, 'sfp_ddm')['status']);
        $this->assertSame('configured', $this->capability($matrix, 'chassis_metrics')['status']);
    }

    public function test_unknown_vendor_requires_exact_model_mib_instead_of_claiming_family_support(): void
    {
        $device = $this->device([
            'vendor' => 'RAISECOM',
            'model' => 'ISCOM6820-EP',
            'firmware' => 'Unknown',
        ]);

        $matrix = (new OltCapabilityRegistry)->forDevice($device);

        $this->assertSame('requires_model_mib', $matrix['profile_status']);
        $this->assertSame('requires_model_mib', $this->capability($matrix, 'model_profile')['status']);
        $this->assertSame('requires_model_mib', $this->capability($matrix, 'onu_identity')['status']);
    }

    public function test_bdcom_family_name_alone_does_not_claim_private_onu_support(): void
    {
        $unknown = $this->device([
            'vendor' => 'BDCOM',
            'model' => 'BDCOM EPON OLT',
            'firmware' => 'Unknown',
        ]);
        $knownModel = $this->device([
            'vendor' => 'BDCOM',
            'model' => 'P3616-2TE',
            'firmware' => 'Unknown',
        ]);

        $registry = new OltCapabilityRegistry;

        $this->assertSame('requires_model_mib', $this->capability($registry->forDevice($unknown), 'onu_identity')['status']);
        $this->assertSame('profile_available', $this->capability($registry->forDevice($knownModel), 'onu_identity')['status']);
    }

    public function test_vsol_exact_profile_reports_configured_opm_and_chassis_oids(): void
    {
        $device = $this->device([
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'V1.0.2R',
        ]);

        $matrix = (new OltCapabilityRegistry)->forDevice($device);

        $this->assertSame('configured', $this->capability($matrix, 'opm')['status']);
        $this->assertSame('configured', $this->capability($matrix, 'chassis_metrics')['status']);
    }

    private function device(array $attributes): NetworkInventoryDevice
    {
        $device = new NetworkInventoryDevice;
        $device->forceFill(array_merge([
            'type' => 'olt',
            'name' => 'Capability Test OLT',
            'ip_address' => '192.0.2.100',
            'snmp_community' => 'read-only',
            'snmp_version' => '2C',
            'ssh_enabled' => false,
            'username' => null,
            'password' => null,
            'adapter_config' => '{}',
        ], $attributes));

        return $device;
    }

    private function capability(array $matrix, string $key): array
    {
        foreach ($matrix['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        self::fail('Capability '.$key.' was not found in the matrix.');
    }
}
