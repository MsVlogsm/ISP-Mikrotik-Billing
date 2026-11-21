<?php

namespace App\Services\Olt;

use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\OltLiveMetricSnapshot;
use App\Models\OltMonitoringSnapshot;

class OltCapabilityRegistry
{
    /**
     * Vendor families that already have an adapter/profile entry in X-Link.
     * This denotes a family-level adapter, not universal model/firmware support.
     */
    private const FAMILY_PROFILES = [
        'VSOL' => 'V-SOL EPON authInfoTable profiles',
        'BDCOM' => 'BDCOM EPON family profile',
        'HUAWEI' => 'Huawei private-MIB family profile',
        'C-DATA' => 'C-Data family profile',
        'HSGQ' => 'HSGQ standard-MIB and custom-OID profile',
        'PHOTON' => 'PHOTON standard-MIB and custom-OID profile',
        'ZTE' => 'ZTE ZXA10 GPON family profile',
        'FIBERHOME' => 'FiberHome standard-MIB and custom-OID profile',
        'NOKIA' => 'Nokia standard-MIB and custom-OID profile',
        'GENERIC' => 'RFC standard SNMP profile',
    ];

    /**
     * Return a capability view derived from the inventory record and the
     * latest stored read-only observations. Missing values remain unknown.
     */
    public function forDevice(
        NetworkInventoryDevice $device,
        ?NetworkBridgeFdbSnapshot $latestFdb = null,
        ?OltLiveMetricSnapshot $latestLiveMetrics = null,
        ?OltMonitoringSnapshot $latestOnuSnapshot = null,
        int $ddmReadings = 0,
    ): array {
        $config = $this->deviceConfig($device);
        $vendor = $this->normalizeVendor((string) $device->vendor);
        $model = strtoupper(trim((string) $device->model));
        $firmware = strtoupper(trim((string) $device->firmware));
        $familyProfile = self::FAMILY_PROFILES[$vendor] ?? null;
        $exactVsolV102 = $vendor === 'VSOL'
            && in_array($model, ['V1601E04-DP', 'V1600D4'], true)
            && $firmware === 'V1.0.2R';

        $customOnuOids = is_array($config['onu_oids'] ?? null) && $config['onu_oids'] !== [];
        $exactBdcomP3616 = $vendor === 'BDCOM' && str_contains($model, 'P3616-2TE');
        $profileOnuConfigured = $exactBdcomP3616;
        $onuStatus = $exactVsolV102
            ? 'verified'
            : ($customOnuOids ? 'configured' : ($profileOnuConfigured ? 'profile_available' : 'requires_model_mib'));

        $fdbVerified = $latestFdb
            && $latestFdb->status === 'ok'
            && (int) $latestFdb->entry_count > 0;

        $interfaceCount = (int) ($latestLiveMetrics?->interface_count ?? 0);
        $onuRxSamples = (int) ($latestOnuSnapshot?->rx_sample_count ?? 0);
        $hasHardwareReadings = collect([
            $latestLiveMetrics?->cpu_percent,
            $latestLiveMetrics?->memory_percent,
            $latestLiveMetrics?->chassis_temp_c,
        ])->contains(fn ($value) => $value !== null);

        $sfpDdmConfigured = is_array($config['sfp_ddm_oids'] ?? null)
            && $config['sfp_ddm_oids'] !== [];
        $vsolProfile = $exactVsolV102
            ? (array) config('olt_vsol.snmp_profiles.v1600d4_auth_info_table', [])
            : [];
        $hardwareOids = array_merge(
            (array) ($vsolProfile['hardware_oids'] ?? []),
            is_array($config['hardware_oids'] ?? null) ? $config['hardware_oids'] : [],
        );
        $opmOids = array_merge(
            (array) ($vsolProfile['opm_oids'] ?? []),
            is_array($config['opm_oids'] ?? null) ? $config['opm_oids'] : [],
        );
        $hardwareOidsConfigured = $hardwareOids !== [];
        $opmOidsConfigured = $opmOids !== [];

        $sshConfigured = (bool) $device->ssh_enabled
            && filled($device->username)
            && filled($device->password);
        $proxyRouterId = (int) data_get($config, 'bridge_fdb.proxy_router_id', 0);

        $modelDetail = $familyProfile
            ? $familyProfile.'. Exact private-MIB support is still model/firmware dependent.'
            : 'No X-Link vendor family profile is registered. Add a vendor MIB verified against the exact model and firmware.';

        $items = [
            $this->item(
                'model_profile',
                'Vendor / model / firmware profile',
                $exactVsolV102 ? 'verified' : ($familyProfile ? 'profile_available' : 'requires_model_mib'),
                $exactVsolV102
                    ? 'V-SOL V1601E04-DP / live V1600D4 family mapping with firmware V1.0.2R.'
                    : $modelDetail,
                $exactVsolV102 ? 'live-model-and-firmware-record' : 'inventory-and-adapter-catalogue',
            ),
            $this->item(
                'standard_snmp',
                'Standard SNMP / IF-MIB',
                filled($device->snmp_community) ? ($interfaceCount > 0 ? 'verified' : 'configured') : 'not_configured',
                $interfaceCount > 0
                    ? $interfaceCount.' interfaces returned by the latest live read-only poll.'
                    : (filled($device->snmp_community) ? 'Read-only community is configured; no interface sample is stored in the latest live-metrics snapshot.' : 'No SNMP community is configured.'),
                'standard-rfc-mib',
            ),
            $this->item(
                'onu_identity',
                'ONU identity / PON mapping',
                $onuStatus,
                match ($onuStatus) {
                    'verified' => 'V-SOL authInfoTable profile is selected for this model/firmware; ONU ID, PON, LLID, state and MAC columns are defined.',
                    'configured' => 'Device-specific ONU OIDs are present in adapter_config.',
                    'profile_available' => 'A vendor-family profile exists, but the exact model/firmware must be confirmed before treating its private OIDs as verified.',
                    default => 'Exact-model vendor MIB/OIDs are required. Unknown ONU identity must remain unmapped.',
                },
                $exactVsolV102
                    ? 'olt_vsol.snmp_profiles.v1600d4_auth_info_table'
                    : ($exactBdcomP3616 ? 'MultiVendorSnmpReadOnlyAdapter.bdcom_p3616_2te' : 'device-adapter-config.onu_oids'),
            ),
            $this->item(
                'opm',
                'ONU optical OPM (RX / TX)',
                $onuRxSamples > 0 ? 'verified' : (($opmOidsConfigured || $exactVsolV102) ? 'configured' : 'not_configured'),
                $onuRxSamples > 0
                    ? $onuRxSamples.' ONU RX samples were stored in the latest OLT monitoring snapshot.'
                    : (($opmOidsConfigured || $exactVsolV102)
                        ? 'An OPM OID profile exists; only fresh numeric readings are shown. Empty or stale walks do not become estimated power values.'
                        : 'No exact-model OPM OID profile is configured.'),
                $exactVsolV102 ? 'olt_vsol.snmp_profiles.v1600d4_auth_info_table.opm_oids' : 'device-adapter-config.opm_oids',
            ),
            $this->item(
                'bridge_fdb',
                'Layer-2 Bridge FDB',
                $fdbVerified ? 'verified' : 'not_verified',
                $fdbVerified
                    ? strtoupper((string) $latestFdb->source).' returned '.(int) $latestFdb->entry_count.' entries; '.(int) $latestFdb->learned_count.' learned.'
                    : 'No successful, non-empty FDB snapshot is stored for this OLT.',
                $fdbVerified ? 'live-read-only-fdb-snapshot' : 'awaiting-successful-snapshot',
            ),
            $this->item(
                'sfp_ddm',
                'SFP DDM (Tx / temperature / voltage / bias)',
                $ddmReadings > 0 ? 'verified' : ($sfpDdmConfigured ? 'configured' : 'not_configured'),
                $ddmReadings > 0
                    ? $ddmReadings.' SFP ports have numeric DDM values from explicitly configured OIDs.'
                    : ($sfpDdmConfigured
                        ? 'Per-device DDM OIDs are configured, but the latest poll has not stored numeric readings.'
                        : 'Vendor-private DDM OIDs are not configured. Standard ENTITY-SENSOR/ENTITY-MIB walks returned no rows on the live V-SOL OLT check on 2026-11-22; this does not prove all SFP transceivers lack DDM.'),
                $sfpDdmConfigured ? 'device-adapter-config.sfp_ddm_oids' : 'standard-entity-mib-probe',
            ),
            $this->item(
                'chassis_metrics',
                'Chassis CPU / memory / temperature',
                $hasHardwareReadings ? 'verified' : ($hardwareOidsConfigured ? 'configured' : 'not_configured'),
                $hasHardwareReadings
                    ? 'At least one chassis metric is present in the latest live-metrics snapshot.'
                    : ($hardwareOidsConfigured
                        ? 'Hardware OIDs are configured; no numeric chassis reading is currently stored.'
                        : 'No exact-model hardware OIDs are configured. Missing chassis readings remain unavailable.'),
                $exactVsolV102
                    ? 'olt_vsol.snmp_profiles.v1600d4_auth_info_table.hardware_oids'
                    : 'device-adapter-config.hardware_oids',
            ),
            $this->item(
                'ssh_fallback',
                'SSH read-only CLI fallback',
                $sshConfigured ? 'configured' : 'disabled',
                $sshConfigured
                    ? 'SSH is enabled with saved device credentials. Only the fixed read-only MAC-table command allowlist is eligible.'
                    : 'SSH fallback is not enabled with saved credentials.',
                'explicit-device-ssh-settings',
            ),
            $this->item(
                'snmp_proxy',
                'MikroTik SNMP proxy fallback',
                $proxyRouterId > 0 ? 'configured' : 'not_configured',
                $proxyRouterId > 0
                    ? 'RouterOS proxy router ID '.$proxyRouterId.' is explicitly selected in adapter_config.'
                    : 'No RouterOS SNMP proxy router is selected for this OLT.',
                'device-adapter-config.bridge_fdb.proxy_router_id',
            ),
            $this->item(
                'telnet',
                'Telnet fallback',
                'disabled',
                'Not used by the P1 collector. No default credentials or unencrypted device login is attempted.',
                'security-policy',
            ),
        ];

        return [
            'vendor' => $vendor !== '' ? $vendor : 'Unknown',
            'model' => $device->model ?: 'Not recorded',
            'firmware' => $device->firmware ?: 'Not recorded',
            'profile_status' => $exactVsolV102 ? 'verified' : ($familyProfile ? 'profile_available' : 'requires_model_mib'),
            'items' => $items,
            'verified_count' => collect($items)->where('status', 'verified')->count(),
            'configured_count' => collect($items)->where('status', 'configured')->count(),
            'requires_model_mib_count' => collect($items)->where('status', 'requires_model_mib')->count(),
        ];
    }

    private function item(string $key, string $label, string $status, string $detail, string $source): array
    {
        $labels = [
            'verified' => 'Verified live',
            'configured' => 'Configured',
            'profile_available' => 'Family profile',
            'requires_model_mib' => 'Model MIB required',
            'not_configured' => 'Not configured',
            'not_verified' => 'Not verified',
            'disabled' => 'Disabled',
        ];

        return compact('key', 'label', 'status', 'detail', 'source') + [
            'status_label' => $labels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
        ];
    }

    private function deviceConfig(NetworkInventoryDevice $device): array
    {
        $config = $device->adapter_config;
        if (is_array($config)) {
            return $config;
        }

        if (is_string($config) && trim($config) !== '') {
            $decoded = json_decode($config, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeVendor(string $vendor): string
    {
        $vendor = strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim($vendor)) ?? '');

        return match ($vendor) {
            'VSOLUTION', 'VSOL' => 'VSOL',
            'CDATA', 'CDATAOLT' => 'C-DATA',
            'HUAWEI' => 'HUAWEI',
            'BDCOM' => 'BDCOM',
            'HSGQ' => 'HSGQ',
            'PHOTON' => 'PHOTON',
            'ZTE' => 'ZTE',
            'FIBERHOME' => 'FIBERHOME',
            'NOKIA', 'ALCATELNOKIA' => 'NOKIA',
            'GENERIC', 'UNKNOWN' => 'GENERIC',
            default => strtoupper(trim($vendor)),
        };
    }
}
