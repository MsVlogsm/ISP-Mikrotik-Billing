<?php

namespace App\Services\Olt;

use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BridgeFdbSnmpService
{
    private const QBRIDGE_PORT = '1.3.6.1.2.1.17.7.1.2.2.1.2';

    private const QBRIDGE_STATUS = '1.3.6.1.2.1.17.7.1.2.2.1.3';

    private const QBRIDGE_VLAN_FDB_ID = '1.3.6.1.2.1.17.7.1.4.2.1.3';

    private const BRIDGE_PORT = '1.3.6.1.2.1.17.4.3.1.2';

    private const BRIDGE_STATUS = '1.3.6.1.2.1.17.4.3.1.3';

    private const BRIDGE_PORT_IFINDEX = '1.3.6.1.2.1.17.1.4.1.2';

    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private string $snmpVersion = '2C';

    public function __construct(private readonly BridgeFdbOidParser $parser) {}

    public function poll(NetworkInventoryDevice $device): NetworkBridgeFdbSnapshot
    {
        abort_unless(in_array($device->type, ['olt', 'switch'], true), 404);

        $started = microtime(true);
        $sampledAt = now();
        $config = $this->adapterConfig($device);
        $community = trim((string) ($config['bridge_fdb']['community'] ?? $config['community'] ?? $device->snmp_community ?? ''));
        $this->snmpVersion = strtoupper((string) ($device->snmp_version ?: '2C'));
        $host = trim((string) ($device->ip_address ?: $device->host));
        $port = max(1, min(65535, (int) ($device->snmp_port ?: 161)));
        $timeout = max(150000, min(1500000, (int) config('bridge_fdb.timeout_us', 750000)));
        $retries = max(0, min(1, (int) config('bridge_fdb.retries', 0)));
        $limit = max(100, min(50000, (int) config('bridge_fdb.max_entries', 10000)));

        $source = null;
        $rows = [];
        $error = null;
        $meta = [
            'read_only' => true,
            'writes_performed' => false,
            'protocol' => 'SNMP',
            'host' => $host,
            'snmp_port' => $port,
            'snmp_version' => strtoupper((string) ($device->snmp_version ?: '2C')),
            'entry_limit' => $limit,
            'bridge_port_mapping_available' => false,
            'vlan_mapping_available' => false,
        ];

        try {
            if (! function_exists('snmp2_real_walk') || ($this->snmpVersion === '1' && ! function_exists('snmprealwalk'))) {
                throw new RuntimeException('PHP SNMP extension is not installed; Bridge FDB polling is unavailable.');
            }
            if ($host === '') {
                throw new RuntimeException('The device management IP/host is not configured.');
            }
            if ($community === '') {
                throw new RuntimeException('SNMP community is not configured. Add a read-only community before polling.');
            }
            if ($this->snmpVersion === '3') {
                throw new RuntimeException('This Bridge FDB collector currently supports SNMP v1/v2c only. SNMPv3 credential support is not configured.');
            }

            $snmpHost = str_contains($host, ':') && ! str_starts_with($host, '[')
                ? '['.$host.']:'.$port
                : $host.':'.$port;

            // Prefer VLAN-aware Q-BRIDGE-MIB. Fall back to the classic
            // BRIDGE-MIB only when the Q-BRIDGE forwarding table is empty.
            $qPorts = $this->walk($snmpHost, $community, self::QBRIDGE_PORT, $timeout, $retries);
            if ($qPorts !== []) {
                $source = 'dot1q';
                $portOid = self::QBRIDGE_PORT;
                $statusOid = self::QBRIDGE_STATUS;
                $statuses = $this->walk($snmpHost, $community, $statusOid, $timeout, $retries);
                $vlanFdbIds = $this->walk($snmpHost, $community, self::QBRIDGE_VLAN_FDB_ID, $timeout, $retries);
                $vlanIdsByFdb = $this->vlanIdsByFdb($vlanFdbIds);
                $meta['vlan_mapping_available'] = $vlanIdsByFdb !== [];
                $meta['qbridge_fdb_ids'] = count($vlanIdsByFdb);
            } else {
                $source = 'dot1d';
                $portOid = self::BRIDGE_PORT;
                $statusOid = self::BRIDGE_STATUS;
                $qPorts = $this->walk($snmpHost, $community, $portOid, $timeout, $retries);
                $statuses = $qPorts !== [] ? $this->walk($snmpHost, $community, $statusOid, $timeout, $retries) : [];
                $vlanIdsByFdb = [];
            }

            if ($qPorts === []) {
                throw new RuntimeException('No readable BRIDGE-MIB or Q-BRIDGE-MIB forwarding entries were returned. Check SNMP access/view and device MIB support.');
            }

            if ($statuses === []) {
                throw new RuntimeException('FDB forwarding rows were returned, but the FDB status table is not readable. Check the SNMP view; entry type cannot be safely determined.');
            }

            $bridgePortIfIndexes = $this->walk($snmpHost, $community, self::BRIDGE_PORT_IFINDEX, $timeout, $retries);
            $ifNames = $this->walk($snmpHost, $community, self::IF_NAME, $timeout, $retries);
            $ifDescriptions = $this->walk($snmpHost, $community, self::IF_DESCR, $timeout, $retries);
            $meta['bridge_port_mapping_available'] = $bridgePortIfIndexes !== [];

            $statusByIndex = $this->indexedIntegers($statuses, $statusOid);
            $ifIndexByBridgePort = $this->indexedIntegers($bridgePortIfIndexes, self::BRIDGE_PORT_IFINDEX);
            $ifNameByIndex = $this->indexedValues($ifNames, self::IF_NAME);
            $ifDescriptionByIndex = $this->indexedValues($ifDescriptions, self::IF_DESCR);

            foreach ($qPorts as $returnedOid => $rawBridgePort) {
                if (count($rows) >= $limit) {
                    $meta['truncated'] = true;
                    break;
                }

                $suffix = $this->oidIndex((string) $returnedOid, $portOid);
                if ($suffix === null) {
                    continue;
                }

                $parsed = $this->parser->parseIndex($suffix, $source);
                if ($parsed === null) {
                    continue;
                }

                $statusCode = $statusByIndex[$suffix] ?? null;
                $statusName = $this->parser->statusName($statusCode);
                if ($statusCode === null) {
                    continue;
                }

                $bridgePort = $this->numericValue($rawBridgePort);
                if ($bridgePort === null || $bridgePort < 0) {
                    continue;
                }

                $ifIndex = $ifIndexByBridgePort[$bridgePort] ?? null;
                $ifName = $ifIndex !== null ? ($ifNameByIndex[$ifIndex] ?? $ifDescriptionByIndex[$ifIndex] ?? null) : null;

                $vlans = $source === 'dot1q'
                    ? ($vlanIdsByFdb[$parsed['fdb_id']] ?? [])
                    : [null];

                // A Q-BRIDGE FDB ID can be shared by more than one VLAN. Keep
                // one row per reported VLAN; if no VLAN mapping is exposed,
                // retain the entry with an explicit unknown VLAN.
                if ($vlans === []) {
                    $vlans = [null];
                }

                foreach ($vlans as $vlanId) {
                    if (count($rows) >= $limit) {
                        $meta['truncated'] = true;
                        break 2;
                    }

                    $rows[] = [
                        'mac_address' => $parsed['mac'],
                        'vlan_id' => $vlanId,
                        'fdb_id' => $parsed['fdb_id'],
                        'bridge_port' => $bridgePort,
                        'if_index' => $ifIndex,
                        'interface_name' => is_string($ifName) && trim($ifName) !== '' ? trim($ifName) : null,
                        'entry_status' => $statusName,
                        'status_code' => $statusCode,
                        'source' => $source,
                    ];
                }
            }

            $meta['raw_fdb_rows'] = count($qPorts);
            $meta['vlan_count'] = count(array_unique(array_filter(array_column($rows, 'vlan_id'), static fn ($v) => $v !== null)));
            $meta['interface_count'] = count(array_unique(array_filter(array_column($rows, 'if_index'), static fn ($v) => $v !== null)));
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $duration = max(0, (int) round((microtime(true) - $started) * 1000));
        $counts = [
            'learned' => count(array_filter($rows, static fn ($row) => $row['entry_status'] === 'learned')),
            'other' => count(array_filter($rows, static fn ($row) => $row['entry_status'] === 'other')),
            'self' => count(array_filter($rows, static fn ($row) => $row['entry_status'] === 'self')),
            'management' => count(array_filter($rows, static fn ($row) => $row['entry_status'] === 'management')),
            'invalid' => count(array_filter($rows, static fn ($row) => $row['entry_status'] === 'invalid')),
        ];
        $status = $error !== null ? 'error' : ($rows === [] ? 'empty' : 'ok');

        return DB::transaction(function () use ($device, $sampledAt, $source, $status, $rows, $counts, $duration, $error, $meta) {
            $snapshot = NetworkBridgeFdbSnapshot::create([
                'network_inventory_device_id' => $device->id,
                'sampled_at' => $sampledAt,
                'source' => $source,
                'status' => $status,
                'entry_count' => count($rows),
                'learned_count' => $counts['learned'],
                'other_count' => $counts['other'],
                'self_count' => $counts['self'],
                'management_count' => $counts['management'],
                'invalid_count' => $counts['invalid'],
                'poll_duration_ms' => $duration,
                'error_message' => $error,
                'meta' => $meta,
            ]);

            foreach ($rows as $row) {
                $snapshot->entries()->create($row);
            }

            $retentionDays = max(1, min(365, (int) config('bridge_fdb.snapshot_retention_days', 30)));
            NetworkBridgeFdbSnapshot::query()
                ->where('network_inventory_device_id', $device->id)
                ->where('sampled_at', '<', now()->subDays($retentionDays))
                ->delete();

            return $snapshot->load('entries');
        });
    }

    private function walk(string $host, string $community, string $oid, int $timeout, int $retries): array
    {
        $result = $this->snmpVersion === '1'
            ? @snmprealwalk($host, $community, $oid, $timeout, $retries)
            : @snmp2_real_walk($host, $community, $oid, $timeout, $retries);

        return is_array($result) ? $result : [];
    }

    private function indexedValues(array $walk, string $baseOid): array
    {
        $indexed = [];
        foreach ($walk as $oid => $value) {
            $index = $this->oidIndex((string) $oid, $baseOid);
            if ($index !== null) {
                $indexed[$index] = trim((string) $value);
            }
        }

        return $indexed;
    }

    private function indexedIntegers(array $walk, string $baseOid): array
    {
        $indexed = [];
        foreach ($walk as $oid => $value) {
            $index = $this->oidIndex((string) $oid, $baseOid);
            $number = $this->numericValue($value);
            if ($index !== null && $number !== null) {
                $indexed[(int) $index] = $number;
            }
        }

        return $indexed;
    }

    private function vlanIdsByFdb(array $walk): array
    {
        $byFdb = [];
        foreach ($walk as $oid => $value) {
            $suffix = $this->oidIndex((string) $oid, self::QBRIDGE_VLAN_FDB_ID);
            $fdbId = $this->numericValue($value);
            if ($suffix === null || $fdbId === null || $fdbId < 0) {
                continue;
            }

            // dot1qVlanCurrentEntry is indexed by {TimeMark, VlanIndex};
            // the VLAN ID is the final sub-identifier, not the full suffix.
            $parts = explode('.', $suffix);
            $vlanIndex = end($parts);
            if (! is_string($vlanIndex) || ! ctype_digit($vlanIndex) || (int) $vlanIndex < 1 || (int) $vlanIndex > 4094) {
                continue;
            }

            $byFdb[$fdbId][] = (int) $vlanIndex;
        }

        foreach ($byFdb as $fdbId => $vlans) {
            $byFdb[$fdbId] = array_values(array_unique($vlans));
        }

        return $byFdb;
    }

    private function numericValue(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return (int) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/(?:INTEGER|Gauge32|Counter32|Unsigned32):\s*(\d+)/i', $value, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/\((\d+)\)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return preg_match('/^\d+$/', trim($value)) ? (int) trim($value) : null;
    }

    private function oidIndex(string $returnedOid, string $baseOid): ?string
    {
        $returned = preg_replace('/^iso\./i', '1.', trim($returnedOid)) ?? trim($returnedOid);
        $returned = ltrim($returned, '.');
        $base = ltrim($baseOid, '.');

        if (! str_starts_with($returned, $base.'.')) {
            return null;
        }

        return substr($returned, strlen($base) + 1);
    }

    private function adapterConfig(NetworkInventoryDevice $device): array
    {
        $raw = $device->adapter_config;
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
