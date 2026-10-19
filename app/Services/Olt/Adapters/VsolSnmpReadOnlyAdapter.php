<?php

namespace App\Services\Olt\Adapters;

use App\Contracts\OltReadOnlyAdapter;
use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;

/**
 * Read-only VSOL SNMP adapter.
 *
 * ONU identity is read from the EPON authInfo2Table. In particular, ONU ID is
 * the second table index/column, not the ordinal row number from the legacy
 * .12.1.9 table; the MAC field is never treated as a serial number.
 */
class VsolSnmpReadOnlyAdapter implements OltReadOnlyAdapter
{
    public function key(): string
    {
        return 'vsol_snmp_readonly';
    }

    public function supports(NetworkInventoryDevice $device): bool
    {
        return $device->type === 'olt'
            && strcasecmp((string) $device->vendor, 'VSOL') === 0
            && data_get($this->config($device), 'transport', 'snmp') === 'snmp';
    }

    public function read(NetworkInventoryDevice $device): array
    {
        if (!function_exists('snmp2_get') || !function_exists('snmp2_real_walk')) {
            return $this->fail($device, 'PHP SNMP extension is not installed.');
        }

        $config = $this->config($device);
        $host = trim((string) ($device->ip_address ?: $device->host));
        $community = (string) ($config['community'] ?? $this->storedCommunity($device));

        if ($host === '') {
            return $this->fail($device, 'OLT management host/IP is not configured.');
        }
        if ($community === '') {
            return $this->fail($device, 'SNMP community is not configured for the adapter.');
        }

        $address = $host;
        $snmpPort = max(1, min(65535, (int) ($device->snmp_port ?: 161)));
        // PHP's snmp2_get/snmp2_real_walk expect host:port, not the CLI
        // snmpget transport syntax (udp:host:port). The latter makes PHP try
        // to resolve "udp" as a hostname and silently returns no ONU rows.
        $host = $address . ':' . $snmpPort;

        $timeout = min(max(100000, (int) ($config['timeout_us'] ?? 500000)), 1000000);
        $retries = min(max(0, (int) ($config['retries'] ?? 0)), 1);

        // Read the device's own model string before choosing a private MIB.
        // Inventory labels can be stale or firmware may report a family string.
        $sysDescrOid = '1.3.6.1.2.1.1.1.0';
        $reportedSysDescrRaw = @snmp2_get($host, $community, $sysDescrOid, $timeout, $retries);
        $reportedSysDescr = $reportedSysDescrRaw === false
            ? ''
            : trim((string) $this->cleanSnmpValue($reportedSysDescrRaw));

        $profile = $this->mibProfile($device, $config, $reportedSysDescr);
        $profiles = (array) config('olt_vsol.snmp_profiles', []);
        $inventoryModelMatch = $this->findModelProfile($profiles, (string) $device->model);
        $sysDescrModelMatch = $reportedSysDescr !== ''
            ? $this->findModelProfile($profiles, $reportedSysDescr)
            : null;
        $modelProfileMismatch = $inventoryModelMatch !== null
            && $sysDescrModelMatch !== null
            && $inventoryModelMatch['key'] !== $sysDescrModelMatch['key'];
        $systemOids = array_merge(
            $profile['system_oids'] ?? [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ],
            is_array($config['oids'] ?? null) ? $config['oids'] : []
        );
        $onuConfig = array_merge(
            $profile['onu_oids'] ?? [],
            is_array($config['onu_oids'] ?? null) ? $config['onu_oids'] : []
        );
        $opmOids = array_merge(
            $profile['opm_oids'] ?? [],
            is_array($config['opm_oids'] ?? null) ? $config['opm_oids'] : []
        );

        $result = [
            'ok' => false,
            'status' => 'reachable',
            'message' => 'Read-only VSOL SNMP probe completed.',
            'device' => [
                'id' => $device->id,
                'host' => $address,
                'vendor' => $device->vendor,
                'model' => $device->model,
                'transport' => 'snmp',
                'snmp_port' => $snmpPort,
                'version' => strtoupper((string) ($config['version'] ?? $device->snmp_version ?? '2C')),
            ],
            'onus' => [],
            'meta' => [
                'read_only' => true,
                'writes_performed' => false,
                'mib_profile' => $profile['key'] ?? (count($onuConfig) ? 'custom_oid_configuration' : 'unsupported_model'),
                'mib_profile_label' => $profile['label'] ?? null,
                'enterprise_oid' => $profile['enterprise_oid'] ?? '1.3.6.1.4.1.37950',
                'mib_table_oid' => $profile['mib_table_oid'] ?? null,
                'mib_profile_source' => $profile['selection_source'] ?? (count($onuConfig) ? 'custom_oid_configuration' : 'unsupported_model'),
                'inventory_model' => trim((string) $device->model),
                'snmp_sys_descr' => $reportedSysDescr !== '' ? $reportedSysDescr : null,
                'model_profile_mismatch' => $modelProfileMismatch,
                'oid_count' => 0,
                'failed_oids' => [],
                'onu_count' => 0,
                'onu_discovery_configured' => count($onuConfig) > 0,
            ],
        ];

        foreach ($systemOids as $name => $oid) {
            if (!is_string($name) || !is_string($oid) || !$this->validOid($oid)) {
                continue;
            }

            $value = $name === 'sysDescr'
                && ltrim(trim($oid), '.') === $sysDescrOid
                && $reportedSysDescrRaw !== false
                    ? $reportedSysDescrRaw
                    : @snmp2_get($host, $community, trim($oid), $timeout, $retries);
            $result['meta']['oid_count']++;
            if ($value === false) {
                $result['meta']['failed_oids'][] = $name;
                continue;
            }

            $result['device'][$name] = $this->cleanSnmpValue($value);
        }

        if ($onuConfig) {
            $result['onus'] = $this->discoverOnus($host, $community, $onuConfig, $timeout, $retries);
            if ($opmOids && $result['onus']) {
                $result['onus'] = $this->enrichOpticalPower($host, $community, $result['onus'], $opmOids, $timeout, $retries);
            }

            $clientMacDiscovery = ($profile['client_mac_discovery'] ?? true)
                ? $this->discoverClientMacsByOnu($host, $community, $timeout, $retries, $result['onus'])
                : ['by_onu' => [], 'fdb_rows' => 0, 'readable' => false];
            foreach ($result['onus'] as &$onu) {
                $onuKey = trim((string) ($onu['pon_port'] ?? '')).'|'.trim((string) ($onu['onu_id'] ?? ''));
                $onu['client_macs'] = $clientMacDiscovery['by_onu'][$onuKey] ?? [];
            }
            unset($onu);

            $result['meta']['client_mac_source'] = ($profile['client_mac_discovery'] ?? true)
                ? 'standard-dot1d-bridge-fdb'
                : null;
            $result['meta']['client_mac_discovery_configured'] = (bool) ($profile['client_mac_discovery'] ?? true);
            $result['meta']['client_mac_count'] = array_sum(array_map(
                static fn ($onu) => count($onu['client_macs'] ?? []),
                $result['onus']
            ));
            $result['meta']['client_mac_onu_count'] = count(array_filter(
                $result['onus'],
                static fn ($onu) => !empty($onu['client_macs'])
            ));
            $result['meta']['client_mac_fdb_rows'] = $clientMacDiscovery['fdb_rows'];
            $result['meta']['client_mac_fdb_readable'] = $clientMacDiscovery['readable'];

            $result['meta']['onu_count'] = count($result['onus']);
            $result['meta']['onu_discovery_configured'] = true;
            $onuCount = count($result['onus']);
            $txCount = count(array_filter($result['onus'], fn ($onu) => is_numeric($onu['tx_power'] ?? null)));
            $rxCount = count(array_filter($result['onus'], fn ($onu) => is_numeric($onu['rx_power'] ?? null)));
            $macCount = count(array_filter($result['onus'], fn ($onu) => trim((string) ($onu['onu_mac'] ?? '')) !== ''));

            $result['meta']['onu_mac_count'] = $macCount;
            $result['meta']['snmp_opm_metric_count'] = $txCount + $rxCount;
            $result['meta']['opm_tx_count'] = $txCount;
            $result['meta']['opm_rx_count'] = $rxCount;
            $result['meta']['opm_tx_coverage'] = $onuCount > 0 ? round(($txCount / $onuCount) * 100, 1) : 0;
            $result['meta']['opm_rx_coverage'] = $onuCount > 0 ? round(($rxCount / $onuCount) * 100, 1) : 0;
            $result['meta']['opm_status'] = ($txCount > 0 && $rxCount > 0)
                ? (($txCount === $onuCount && $rxCount === $onuCount) ? 'ok' : 'partial')
                : 'not_ready';
            $result['meta']['opm_message'] = ($txCount + $rxCount) > 0
                ? "VSOL SNMP supplied {$macCount} ONU MACs; TX {$txCount}/{$onuCount}, RX {$rxCount}/{$onuCount}."
                : 'VSOL ONU inventory was read, but optical TX/RX metrics were unavailable in this poll.';
            $result['meta']['opm_source'] = ($txCount + $rxCount) > 0 ? 'vsol-snmp-opm' : null;
        }

        if ($result['meta']['oid_count'] === 0 && !$onuConfig) {
            $result['status'] = 'not_configured';
            $result['message'] = 'VSOL read-only adapter is ready, but no supported model MIB or custom ONU OIDs are configured.';
            return $result;
        }

        $hasDeviceData = $result['meta']['oid_count'] > 0
            && count($result['meta']['failed_oids']) < $result['meta']['oid_count'];
        $hasOnuData = count($result['onus']) > 0;
        $hasFailures = !empty($result['meta']['failed_oids']);
        $result['ok'] = $hasDeviceData || $hasOnuData;
        $result['status'] = $result['ok'] ? ($hasFailures ? 'partial' : 'ok') : 'unreachable';
        $result['message'] = $result['status'] === 'ok'
            ? 'Configured VSOL MIB OIDs and ONU discovery were read successfully; no OLT changes were made.'
            : ($result['status'] === 'partial'
                ? 'VSOL SNMP returned usable read-only data, but one or more configured system probes failed.'
                : 'VSOL SNMP read failed. No OLT changes were made.');

        if ($modelProfileMismatch) {
            if (($profile['selection_source'] ?? null) === 'snmp_sysdescr') {
                $result['message'] .= " Inventory model {$device->model} resolves to {$inventoryModelMatch['key']}, while live sysDescr {$reportedSysDescr} resolves to {$sysDescrModelMatch['key']}; selected the profile from live sysDescr.";
            } else {
                $result['message'] .= " Inventory model {$device->model} and live sysDescr {$reportedSysDescr} resolve to different MIB profiles; review the explicit profile selection.";
            }
        }

        return $result;
    }

    private function mibProfile(NetworkInventoryDevice $device, array $config, ?string $reportedSysDescr = null): ?array
    {
        $profiles = (array) config('olt_vsol.snmp_profiles', []);
        $requested = trim((string) ($config['mib_profile'] ?? ''));

        if ($requested !== '' && isset($profiles[$requested])) {
            return ['key' => $requested, 'selection_source' => 'configured'] + $profiles[$requested];
        }

        // A supported sysDescr is more reliable than a manually maintained
        // inventory model. Fall back to inventory only when the live hint is
        // missing or does not identify a known profile.
        if (trim((string) $reportedSysDescr) !== '') {
            $liveMatch = $this->findModelProfile($profiles, (string) $reportedSysDescr);
            if ($liveMatch !== null) {
                return ['key' => $liveMatch['key'], 'selection_source' => 'snmp_sysdescr'] + $liveMatch['profile'];
            }
        }

        $inventoryMatch = $this->findModelProfile($profiles, (string) $device->model);
        if ($inventoryMatch !== null) {
            return ['key' => $inventoryMatch['key'], 'selection_source' => 'inventory_model'] + $inventoryMatch['profile'];
        }

        return null;
    }

    private function findModelProfile(array $profiles, string $model): ?array
    {
        $model = strtoupper(trim($model));
        if ($model === '') {
            return null;
        }

        foreach ($profiles as $key => $profile) {
            foreach ((array) ($profile['models'] ?? []) as $candidate) {
                $candidate = strtoupper(trim((string) $candidate));
                if ($candidate !== '' && ($model === $candidate || str_contains($model, $candidate))) {
                    return ['key' => $key, 'profile' => $profile];
                }
            }
        }

        return null;
    }

    private function discoverOnus(string $host, string $community, array $onuConfig, int $timeout, int $retries): array
    {
        $tables = [];

        foreach ($onuConfig as $field => $baseOid) {
            if (!is_string($field) || !is_string($baseOid) || !$this->validOid($baseOid)) {
                continue;
            }

            $walk = $this->walkWithRetry($host, $community, trim($baseOid), $timeout, $retries);
            if (!$walk) {
                continue;
            }

            foreach ($walk as $returnedOid => $value) {
                $index = $this->oidIndex((string) $returnedOid, trim($baseOid));
                if ($index === null || $index === '') {
                    continue;
                }
                $tables[$index][$field] = $this->cleanSnmpValue($value);
            }
        }

        $normalized = [];
        foreach ($tables as $index => $row) {
            $indexParts = explode('.', (string) $index);
            $row['snmp_index'] = (string) $index;
            $row['onu_id'] = $row['onu_id'] ?? end($indexParts);
            $row['pon_port'] = $row['pon_port'] ?? (count($indexParts) > 1 ? $indexParts[0] : null);
            $row['onu_serial'] = $row['onu_serial'] ?? null;
            $row['onu_mac'] = $this->normalizeMac($row['onu_mac'] ?? null);
            $row['onu_type'] = $row['onu_type'] ?? null;
            $row['onu_model'] = $row['onu_model'] ?? null;
            $row['distance_m'] = $this->normalizeMetric($row['distance_m'] ?? null);

            $rawStatus = strtolower(trim((string) ($row['status'] ?? '')));
            $row['status'] = in_array($rawStatus, ['1', 'online', 'up', 'active', 'registered', 'auth success'], true)
                ? 'online'
                : (in_array($rawStatus, ['0', '2', '4', 'offline', 'down', 'inactive', 'lost', 'deregistered', 'unregistered'], true)
                    ? 'offline'
                    : ($rawStatus !== '' ? $rawStatus : 'unknown'));

            $row['rx_power'] = null;
            $row['tx_power'] = null;
            $row['opm_source'] = null;
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Read learned customer/client MACs from the standard IEEE 802.1D bridge FDB.
     *
     * V-Solution exposes per-ONU logical interfaces in ifDescr (for example
     * EPON0/1:23) and uses the corresponding interface index in dot1dTpFdbPort.
     * Only dynamic/learned FDB rows (dot1dTpFdbStatus = 3) are considered.
     * This routine is read-only and never uses SNMP SET.
     *
     * @return array{by_onu: array<string, array<int, string>>, fdb_rows: int, readable: bool}
     */
    private function discoverClientMacsByOnu(
        string $host,
        string $community,
        int $timeout,
        int $retries,
        array $onus
    ): array {
        $fdbPortOid = '1.3.6.1.2.1.17.4.3.1.2'; // dot1dTpFdbPort
        $fdbStatusOid = '1.3.6.1.2.1.17.4.3.1.3'; // dot1dTpFdbStatus
        $bridgePortIfIndexOid = '1.3.6.1.2.1.17.1.4.1.2'; // dot1dBasePortIfIndex
        $ifDescrOid = '1.3.6.1.2.1.2.2.1.2'; // ifDescr

        $fdbPorts = $this->walkWithRetry($host, $community, $fdbPortOid, $timeout, $retries);
        if (!$fdbPorts) {
            return ['by_onu' => [], 'fdb_rows' => 0, 'readable' => false];
        }

        $fdbStatuses = $this->walkWithRetry($host, $community, $fdbStatusOid, $timeout, $retries);
        $bridgePortIfIndexes = $this->walkWithRetry($host, $community, $bridgePortIfIndexOid, $timeout, $retries);
        $ifDescriptions = $this->walkWithRetry($host, $community, $ifDescrOid, $timeout, $retries);

        $statusByIndex = [];
        foreach ($fdbStatuses as $returnedOid => $value) {
            $index = $this->oidIndex((string) $returnedOid, $fdbStatusOid);
            if ($index !== null && $index !== '') {
                $statusByIndex[$index] = trim((string) $this->cleanSnmpValue($value));
            }
        }

        $ifIndexByBridgePort = [];
        foreach ($bridgePortIfIndexes as $returnedOid => $value) {
            $index = $this->oidIndex((string) $returnedOid, $bridgePortIfIndexOid);
            if ($index === null || $index === '') {
                continue;
            }
            $ifIndex = $this->normalizeMetric($this->cleanSnmpValue($value));
            if ($ifIndex !== null) {
                $ifIndexByBridgePort[(int) $index] = (int) $ifIndex;
            }
        }

        $ifDescriptionByIndex = [];
        foreach ($ifDescriptions as $returnedOid => $value) {
            $index = $this->oidIndex((string) $returnedOid, $ifDescrOid);
            if ($index !== null && $index !== '') {
                $ifDescriptionByIndex[(int) $index] = trim((string) $this->cleanSnmpValue($value));
            }
        }

        $knownOnus = [];
        foreach ($onus as $onu) {
            $pon = trim((string) ($onu['pon_port'] ?? ''));
            $onuId = trim((string) ($onu['onu_id'] ?? ''));
            if ($pon !== '' && $onuId !== '') {
                $knownOnus[$pon.'|'.$onuId] = true;
            }
        }

        $byOnu = [];
        foreach ($fdbPorts as $returnedOid => $value) {
            $index = $this->oidIndex((string) $returnedOid, $fdbPortOid);
            if ($index === null || ($statusByIndex[$index] ?? null) !== '3') {
                continue; // Only a currently learned/dynamic FDB entry is safe evidence.
            }

            $parts = explode('.', $index);
            // dot1dTpFdbPort index = {6, six octets of the learned MAC address}.
            if (count($parts) !== 7 || (int) $parts[0] !== 6) {
                continue;
            }
            $octets = array_slice($parts, 1);
            if (count(array_filter($octets, static fn ($part) => ctype_digit($part) && (int) $part >= 0 && (int) $part <= 255)) !== 6) {
                continue;
            }
            $mac = implode(':', array_map(static fn ($part) => sprintf('%02x', (int) $part), $octets));

            $bridgePort = $this->normalizeMetric($this->cleanSnmpValue($value));
            if ($bridgePort === null) {
                continue;
            }
            $bridgePort = (int) $bridgePort;
            $ifIndex = $ifIndexByBridgePort[$bridgePort] ?? $bridgePort;
            $ifDescription = $ifDescriptionByIndex[$ifIndex] ?? '';

            // Some VSOL firmware omits virtual EPON ONU interfaces from
            // dot1dBasePortIfIndex, but exposes them directly in ifDescr using
            // EPON0/<pon>:<onu>. Only use the bridge-port index directly if
            // that exact per-ONU interface pattern is present.
            if (!preg_match('/^EPON\d+\/(\d+):(\d+)$/i', $ifDescription, $match)) {
                $directDescription = $ifDescriptionByIndex[$bridgePort] ?? '';
                if (!preg_match('/^EPON\d+\/(\d+):(\d+)$/i', $directDescription, $match)) {
                    continue;
                }
                $ifDescription = $directDescription;
            }

            $pon = trim((string) $match[1]);
            $onuId = trim((string) $match[2]);
            $onuKey = $pon.'|'.$onuId;
            if (!isset($knownOnus[$onuKey])) {
                continue; // Never create/infer an ONU from the FDB alone.
            }

            $byOnu[$onuKey] ??= [];
            if (!in_array($mac, $byOnu[$onuKey], true)) {
                $byOnu[$onuKey][] = $mac;
            }
        }

        return [
            'by_onu' => $byOnu,
            'fdb_rows' => count($fdbPorts),
            'readable' => true,
        ];
    }

    private function enrichOpticalPower(
        string $host,
        string $community,
        array $onus,
        array $opmOids,
        int $timeout,
        int $retries
    ): array {
        $values = ['rx_power' => [], 'tx_power' => []];

        foreach (['rx_power', 'tx_power'] as $field) {
            $oid = $opmOids[$field] ?? null;
            if (!is_string($oid) || !$this->validOid($oid)) {
                continue;
            }
            $walk = $this->walkWithRetry($host, $community, trim($oid), $timeout, $retries);
            foreach ($walk as $returnedOid => $value) {
                $index = $this->oidIndex((string) $returnedOid, trim($oid));
                if ($index === null || $index === '') {
                    continue;
                }
                $values[$field][$index] = $this->normalizeOpticalPower($this->cleanSnmpValue($value));
            }
        }

        foreach ($onus as &$onu) {
            $index = (string) ($onu['snmp_index'] ?? '');
            $rx = $values['rx_power'][$index] ?? null;
            $tx = $values['tx_power'][$index] ?? null;
            $onu['rx_power'] = $rx;
            $onu['tx_power'] = $tx;
            if ($rx !== null || $tx !== null) {
                $onu['opm_source'] = 'vsol-snmp-opm';
            }
        }
        unset($onu);

        return $onus;
    }

    private function walkWithRetry(string $host, string $community, string $oid, int $timeout, int $retries): array
    {
        $baseTimeout = max(250000, min(1000000, $timeout));
        $baseRetries = max(0, min(2, $retries));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $attemptTimeout = min(1500000, $baseTimeout * $attempt);
            $attemptRetries = min(2, $baseRetries + ($attempt - 1));
            $walk = @snmp2_real_walk($host, $community, $oid, $attemptTimeout, $attemptRetries);
            if (is_array($walk) && $walk) {
                return $walk;
            }
        }

        return [];
    }

    private function oidIndex(string $returnedOid, string $baseOid): ?string
    {
        $returned = preg_replace('/^iso\./i', '1.', $returnedOid) ?? $returnedOid;
        $returned = ltrim($returned, '.');
        $base = ltrim($baseOid, '.');

        if ($returned === $base) {
            return '';
        }

        $prefix = $base . '.';
        return str_starts_with($returned, $prefix) ? substr($returned, strlen($prefix)) : null;
    }

    private function cleanSnmpValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $value = preg_replace('/^[A-Z0-9-]+:\s*/i', '', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B\"");
    }

    private function validOid(string $oid): bool
    {
        return (bool) preg_match('/^\.?\d+(?:\.\d+)*$/', trim($oid));
    }

    private function normalizeMac(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = preg_replace('/^0x/i', '', trim((string) $value)) ?? trim((string) $value);
        $hex = preg_replace('/[^0-9a-f]/i', '', $raw) ?? '';
        if (strlen($hex) !== 12) {
            return null;
        }

        return strtolower(implode(':', str_split($hex, 2)));
    }

    private function normalizeOpticalPower(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/\((-?\d+(?:\.\d+)?)\s*dBm\)/i', (string) $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*dBm/i', (string) $value, $m)) {
            return (float) $m[1];
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeMetric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', (string) $value, $m)) {
            return (float) $m[0];
        }

        return null;
    }

    private function storedCommunity(NetworkInventoryDevice $device): string
    {
        $raw = $device->getRawOriginal('snmp_community');
        if (!$raw) {
            return '';
        }

        try {
            return (string) Crypt::decryptString($raw);
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    private function config(NetworkInventoryDevice $device): array
    {
        $raw = $device->adapter_config;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function fail(NetworkInventoryDevice $device, string $message): array
    {
        return [
            'ok' => false,
            'status' => 'not_ready',
            'message' => $message,
            'device' => [
                'id' => $device->id,
                'host' => $device->ip_address ?: $device->host,
                'vendor' => $device->vendor,
                'model' => $device->model,
                'transport' => 'snmp',
            ],
            'onus' => [],
            'meta' => ['read_only' => true, 'writes_performed' => false, 'oid_count' => 0],
        ];
    }
}
