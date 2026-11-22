<?php

namespace App\Services\Olt;

use App\Models\NetworkBridgeFdbMovement;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\RouterList;
use App\Services\MikrotikApiService;
use Illuminate\Support\Facades\DB;
use phpseclib3\Net\SSH2;
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

    private ?MikrotikApiService $proxyApi = null;

    private ?RouterList $proxyRouter = null;

    private string $collector = 'snmp-direct';

    public function __construct(private readonly BridgeFdbOidParser $parser) {}

    public function poll(NetworkInventoryDevice $device): NetworkBridgeFdbSnapshot
    {
        abort_unless(in_array($device->type, ['olt', 'switch'], true), 404);

        // The scheduled command reuses this service for multiple devices.
        // Reset per-device proxy/session state so one OLT can never inherit
        // another OLT's explicitly configured RouterOS proxy.
        $this->proxyApi = null;
        $this->proxyRouter = null;
        $this->collector = 'snmp-direct';

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
            if ($host === '') {
                throw new RuntimeException('The device management IP/host is not configured.');
            }
            $sshFallbackReady = (bool) $device->ssh_enabled
                && filled($device->username)
                && filled($device->password);
            if ($community === '' && ! $sshFallbackReady) {
                throw new RuntimeException('SNMP community is not configured. Add a read-only community or explicitly enable SSH with saved read-only credentials before polling.');
            }
            if ($this->snmpVersion === '3' && ! $sshFallbackReady) {
                throw new RuntimeException('This Bridge FDB collector currently supports SNMP v1/v2c only. SNMPv3 credential support is not configured.');
            }

            $snmpHost = str_contains($host, ':') && ! str_starts_with($host, '[')
                ? '['.$host.']:'.$port
                : $host.':'.$port;

            // Prefer VLAN-aware Q-BRIDGE-MIB. Fall back to the classic
            // BRIDGE-MIB only when the Q-BRIDGE forwarding table is empty.
            $qPorts = $this->walk($snmpHost, $community, self::QBRIDGE_PORT, $timeout, $retries, $device, $config);
            if ($qPorts !== []) {
                $source = 'dot1q';
                $portOid = self::QBRIDGE_PORT;
                $statusOid = self::QBRIDGE_STATUS;
                $statuses = $this->walk($snmpHost, $community, $statusOid, $timeout, $retries, $device, $config);
                $vlanFdbIds = $this->walk($snmpHost, $community, self::QBRIDGE_VLAN_FDB_ID, $timeout, $retries, $device, $config);
                $vlanIdsByFdb = $this->vlanIdsByFdb($vlanFdbIds);
                $meta['vlan_mapping_available'] = $vlanIdsByFdb !== [];
                $meta['qbridge_fdb_ids'] = count($vlanIdsByFdb);
            } else {
                $source = 'dot1d';
                $portOid = self::BRIDGE_PORT;
                $statusOid = self::BRIDGE_STATUS;
                $qPorts = $this->walk($snmpHost, $community, $portOid, $timeout, $retries, $device, $config);
                $statuses = $qPorts !== [] ? $this->walk($snmpHost, $community, $statusOid, $timeout, $retries, $device, $config) : [];
                $vlanIdsByFdb = [];
            }

            if ($qPorts === []) {
                $rows = $this->collectCliFallback($device, $config);
                if ($rows !== []) {
                    $source = 'cli';
                    $statuses = ['cli' => true];
                    $meta['collector'] = 'ssh-cli';
                    $meta['protocol'] = 'SSH';
                    $meta['cli_command_source'] = 'configured-read-only-command';
                } else {
                    throw new RuntimeException('No readable Q-BRIDGE-MIB or BRIDGE-MIB rows were returned. Configure an explicit MikroTik SNMP proxy or SSH-enabled device for read-only fallback.');
                }
            }

            if ($source !== 'cli' && $statuses === []) {
                throw new RuntimeException('FDB forwarding rows were returned, but the FDB status table is not readable. Check the SNMP view; entry type cannot be safely determined.');
            }

            if ($source !== 'cli') {
                $bridgePortIfIndexes = $this->walk($snmpHost, $community, self::BRIDGE_PORT_IFINDEX, $timeout, $retries, $device, $config);
                $ifNames = $this->walk($snmpHost, $community, self::IF_NAME, $timeout, $retries, $device, $config);
                $ifDescriptions = $this->walk($snmpHost, $community, self::IF_DESCR, $timeout, $retries, $device, $config);
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
            } else {
                $meta['raw_fdb_rows'] = count($rows);
                $meta['vlan_count'] = count(array_unique(array_filter(array_column($rows, 'vlan_id'), static fn ($v) => $v !== null)));
                $meta['interface_count'] = count(array_filter(array_column($rows, 'interface_name')));
            }
            $meta['collector'] = $meta['collector'] ?? $this->collector;
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
            $previousSnapshot = NetworkBridgeFdbSnapshot::query()
                ->where('network_inventory_device_id', $device->id)
                ->where('status', 'ok')
                ->where('sampled_at', '<=', $sampledAt)
                ->latest('sampled_at')
                ->first();

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

            if ($previousSnapshot && $snapshot->status === 'ok') {
                $this->recordMovements($device, $previousSnapshot, $snapshot);
            }

            $retentionDays = max(1, min(365, (int) config('bridge_fdb.snapshot_retention_days', 30)));
            NetworkBridgeFdbSnapshot::query()
                ->where('network_inventory_device_id', $device->id)
                ->where('sampled_at', '<', now()->subDays($retentionDays))
                ->delete();

            return $snapshot->load('entries');
        });
    }

    private function recordMovements(
        NetworkInventoryDevice $device,
        NetworkBridgeFdbSnapshot $previousSnapshot,
        NetworkBridgeFdbSnapshot $snapshot
    ): void {
        $previous = $previousSnapshot->entries()
            ->where('entry_status', 'learned')
            ->get()
            ->keyBy(fn ($entry) => strtolower($entry->mac_address).'|'.($entry->vlan_id ?? ''));

        $current = $snapshot->entries()
            ->where('entry_status', 'learned')
            ->get();

        foreach ($current as $entry) {
            $key = strtolower($entry->mac_address).'|'.($entry->vlan_id ?? '');
            $before = $previous->get($key);
            if (! $before) {
                continue;
            }

            $fromInterface = trim((string) $before->interface_name);
            $toInterface = trim((string) $entry->interface_name);
            $fromIfIndex = $before->if_index;
            $toIfIndex = $entry->if_index;
            $moved = ($fromIfIndex !== null && $toIfIndex !== null && (int) $fromIfIndex !== (int) $toIfIndex)
                || ($fromInterface !== '' && $toInterface !== '' && strcasecmp($fromInterface, $toInterface) !== 0);

            if (! $moved) {
                continue;
            }

            $movement = NetworkBridgeFdbMovement::create([
                'network_inventory_device_id' => $device->id,
                'snapshot_id' => $snapshot->id,
                'previous_snapshot_id' => $previousSnapshot->id,
                'mac_address' => $entry->mac_address,
                'vlan_id' => $entry->vlan_id,
                'from_interface' => $fromInterface !== '' ? $fromInterface : null,
                'to_interface' => $toInterface !== '' ? $toInterface : null,
                'from_if_index' => $fromIfIndex,
                'to_if_index' => $toIfIndex,
                'moved_at' => $snapshot->sampled_at,
                'moves_in_10m' => 1,
                'is_flapping' => false,
            ]);

            $recentMoves = NetworkBridgeFdbMovement::query()
                ->where('network_inventory_device_id', $device->id)
                ->where('mac_address', $entry->mac_address)
                ->where('moved_at', '>=', $snapshot->sampled_at->copy()->subMinutes(10))
                ->count();

            $movement->update([
                'moves_in_10m' => $recentMoves,
                'is_flapping' => $recentMoves >= 3,
            ]);
        }
    }

    private function walk(
        string $host,
        string $community,
        string $oid,
        int $timeout,
        int $retries,
        NetworkInventoryDevice $device,
        array $config
    ): array {
        $result = [];
        if ($community !== '' && $this->snmpVersion === '1' && function_exists('snmprealwalk')) {
            $result = @snmprealwalk($host, $community, $oid, $timeout, $retries);
        } elseif ($community !== '' && in_array($this->snmpVersion, ['2C', '2c'], true) && function_exists('snmp2_real_walk')) {
            $result = @snmp2_real_walk($host, $community, $oid, $timeout, $retries);
        }

        if (is_array($result) && $result !== []) {
            $this->collector = 'snmp-direct';

            return $result;
        }

        $proxied = $this->proxyWalk($device, $config, $host, $community, $oid);
        if ($proxied !== []) {
            $this->collector = 'mikrotik-snmp-proxy';
        }

        return $proxied;
    }

    private function proxyWalk(
        NetworkInventoryDevice $device,
        array $config,
        string $host,
        string $community,
        string $oid
    ): array {
        $proxyId = (int) data_get($config, 'bridge_fdb.proxy_router_id', 0);
        if ($proxyId <= 0 || $this->snmpVersion === '3') {
            return [];
        }

        if ($this->proxyRouter === null) {
            $this->proxyRouter = RouterList::query()
                ->whereKey($proxyId)
                ->where('action', 'connected')
                ->first();

            if (! $this->proxyRouter || ! $this->proxyRouter->api_port) {
                return [];
            }

            try {
                $this->proxyApi = new MikrotikApiService(
                    $this->proxyRouter->ip_address,
                    (int) $this->proxyRouter->api_port,
                    (string) $this->proxyRouter->username,
                    (string) $this->proxyRouter->password,
                    5,
                    (bool) $this->proxyRouter->api_ssl
                );
            } catch (\Throwable) {
                return [];
            }
        }

        if (! $this->proxyApi || ! $this->proxyRouter) {
            return [];
        }

        $response = $this->proxyApi->executeCommand('/tool/snmp-walk', [
            'oid' => $oid,
            'address' => trim((string) ($device->ip_address ?: $device->host)),
            'port' => (string) ($device->snmp_port ?: 161),
            'tries' => '1',
            'try-timeout' => '750ms',
            'version' => $this->snmpVersion === '1' ? '1' : '2c',
            'community' => $community,
        ]);

        if (! is_array($response) || isset($response['!trap']) || isset($response['!fatal'])) {
            return [];
        }

        $rows = [];
        foreach ($response as $row) {
            if (! is_array($row)) {
                continue;
            }

            $returnedOid = trim((string) ($row['oid'] ?? ''));
            if ($returnedOid === '' || ! isset($row['value'])) {
                continue;
            }

            $rows[$returnedOid] = (string) $row['value'];
        }

        return $rows;
    }

    /**
     * Collect MAC rows through an explicitly enabled SSH account only.
     * The command is selected from a fixed vendor allowlist; no user command,
     * default credential, Telnet, enable-mode or configuration command is used.
     */
    private function collectCliFallback(NetworkInventoryDevice $device, array $config): array
    {
        if (! $device->ssh_enabled || ! filled($device->username) || ! filled($device->password)) {
            return [];
        }

        $vendor = strtoupper(trim((string) $device->vendor));
        $commands = match (true) {
            str_contains($vendor, 'VSOL') => ['show mac address-table', 'show mac-address dynamic', 'show mac-address epon all', 'show mac-address gpon all'],
            str_contains($vendor, 'BDCOM') => ['show mac-address-table', 'show mac-address dynamic'],
            str_contains($vendor, 'HUAWEI') => ['display mac-address dynamic'],
            str_contains($vendor, 'C-DATA'), str_contains($vendor, 'CDATA') => ['show mac address-table', 'show mac-address-table'],
            str_contains($vendor, 'ZTE') => ['show mac'],
            str_contains($vendor, 'RAISECOM') => ['show mac-address'],
            str_contains($vendor, 'HSGQ'), str_contains($vendor, 'AVEIS') => ['show mac-address dynamic', 'show mac address-table'],
            default => ['show mac address-table'],
        };

        try {
            $ssh = new SSH2(
                (string) ($device->ip_address ?: $device->host),
                (int) ($device->ssh_port ?: 22),
                max(3, min(8, (int) data_get($config, 'bridge_fdb.ssh_timeout_seconds', 5)))
            );
            $ssh->setTimeout(max(3, min(8, (int) data_get($config, 'bridge_fdb.ssh_timeout_seconds', 5))));
            if (! $ssh->login((string) $device->username, (string) $device->password)) {
                return [];
            }

            foreach ($commands as $command) {
                $output = $ssh->exec($command);
                if (! is_string($output) || trim($output) === '' || preg_match('/(unknown command|invalid command|incomplete command|not authorized)/i', $output)) {
                    continue;
                }

                $rows = $this->parseCliMacRows($output);
                if ($rows !== []) {
                    return $rows;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    private function parseCliMacRows(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/(?<![0-9a-f])(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}(?![0-9a-f])/i', $line, $macMatch)) {
                if (! preg_match('/(?<![0-9a-f])[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}(?![0-9a-f])/i', $line, $macMatch)) {
                    continue;
                }
            }

            $hex = preg_replace('/[^0-9a-f]/i', '', $macMatch[0]) ?? '';
            if (strlen($hex) !== 12) {
                continue;
            }
            $mac = strtolower(implode(':', str_split($hex, 2)));
            if ($mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
                continue;
            }

            $interface = null;
            if (preg_match('/\b((?:EPON|GPON)\S+|(?:GE|XGE|ETH|GIGE|GIGABITETHERNET|SFP)\S+|(?:gpon|epon)-onu_\S+|(?:xgei|eth|gei)\S+)/i', $line, $ifMatch)) {
                $interface = trim($ifMatch[1], ',;');
            }

            $vlan = null;
            if (preg_match('/^\s*(\d{1,4})\s+/', $line, $vlanMatch)) {
                $candidate = (int) $vlanMatch[1];
                if ($candidate >= 1 && $candidate <= 4094) {
                    $vlan = $candidate;
                }
            }

            $isLearned = preg_match('/\b(dynamic|learned|aging)\b/i', $line) === 1;
            $rows[] = [
                'mac_address' => $mac,
                'vlan_id' => $vlan,
                'fdb_id' => null,
                'bridge_port' => null,
                'if_index' => null,
                'interface_name' => $interface,
                'entry_status' => $isLearned ? 'learned' : 'other',
                'status_code' => null,
                'source' => 'cli',
            ];
        }

        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['mac_address'].'|'.($row['vlan_id'] ?? '').'|'.($row['interface_name'] ?? '')] = $row;
        }

        return array_values($unique);
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
                // Keep composite indexes (e.g. Q-BRIDGE FDB-ID + MAC) intact.
                // PHP automatically casts plain numeric string keys to integers,
                // preserving bridge-port lookups without breaking FDB status joins.
                $indexed[$index] = $number;
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
