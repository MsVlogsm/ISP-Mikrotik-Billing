<?php

namespace App\Services;

use App\Models\MikrotikBridgeHostEntry;
use App\Models\MikrotikBridgeHostMovement;
use App\Models\MikrotikBridgeHostSnapshot;
use App\Models\RouterList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MikrotikBridgeHostService
{
    private array $collectionMetadata = [];

    public function poll(RouterList $router): MikrotikBridgeHostSnapshot
    {
        $this->collectionMetadata = [];
        $started = microtime(true);
        $sampledAt = now();
        $error = null;
        $hosts = [];

        try {
            $hosts = $this->fetchHosts($router);
        } catch (Throwable $exception) {
            $error = Str::limit($exception->getMessage(), 1500);
        }

        $pppoeSessions = array_values(array_filter($hosts, static fn (mixed $host): bool => is_array($host) && ($host['_source'] ?? null) === 'pppoe-caller-id'));
        $pppoeCallerIdCount = count(array_filter($pppoeSessions, static function (array $host): bool {
            $hexMac = preg_replace('/[^0-9a-f]/i', '', (string) ($host['mac-address'] ?? '')) ?? '';

            return strlen($hexMac) === 12;
        }));
        $maxEntries = max(100, min(50000, (int) config('mikrotik_bridge_host.max_entries', 10000)));
        $truncated = count($hosts) > $maxEntries;
        $rows = $error === null ? $this->normalizeHosts(array_slice($hosts, 0, $maxEntries)) : [];
        $pppoeStoredCount = count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) ($row['router_entry_id'] ?? ''), 'pppoe:')));
        $pppoeVlanMappedCount = count(array_filter($pppoeSessions, static fn (array $host): bool => ($host['_vlan_mapping_source'] ?? null) !== null));
        $pppoeVlanAmbiguousCount = count(array_filter($pppoeSessions, static fn (array $host): bool => (bool) ($host['_vlan_mapping_ambiguous'] ?? false)));
        $duration = max(0, (int) round((microtime(true) - $started) * 1000));
        $status = $error !== null ? 'error' : ($rows === [] ? 'empty' : 'ok');
        $counts = [
            'dynamic' => count(array_filter($rows, static fn (array $row): bool => $row['is_dynamic'])),
            'local' => count(array_filter($rows, static fn (array $row): bool => $row['is_local'])),
            'external' => count(array_filter($rows, static fn (array $row): bool => $row['is_external'])),
            'disabled' => count(array_filter($rows, static fn (array $row): bool => $row['is_disabled'])),
            'invalid' => count(array_filter($rows, static fn (array $row): bool => $row['is_invalid'])),
        ];
        $meta = [
            'read_only' => true,
            'writes_performed' => false,
            'protocol' => $router->api_ssl ? 'RouterOS API-SSL' : 'RouterOS API',
            'api_command' => '/interface/bridge/host/print + /ppp/active/print + /interface/pppoe-server/print + /interface/pppoe-server/server/print + /interface/vlan/print',
            'api_commands' => [
                '/interface/bridge/host/print',
                '/ppp/active/print',
                '/interface/pppoe-server/print',
                '/interface/pppoe-server/server/print',
                '/interface/vlan/print',
            ],
            'pppoe_vlan_mapped_count' => $pppoeVlanMappedCount,
            'pppoe_vlan_unmapped_count' => max(0, count($pppoeSessions) - $pppoeVlanMappedCount),
            'pppoe_vlan_ambiguous_count' => $pppoeVlanAmbiguousCount,
            'bridge_host_received_count' => count($hosts) - count($pppoeSessions),
            'pppoe_active_session_count' => count($pppoeSessions),
            'pppoe_caller_id_count' => $pppoeCallerIdCount,
            'pppoe_stored_count' => $pppoeStoredCount,
            'received_count' => count($hosts),
            'stored_count' => count($rows),
            'truncated' => $truncated,
        ];
        $meta = array_merge($meta, $this->collectionMetadata);

        return DB::transaction(function () use ($router, $sampledAt, $status, $rows, $counts, $duration, $error, $meta): MikrotikBridgeHostSnapshot {
            $previousSnapshot = MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $router->id)
                ->whereIn('status', ['ok', 'empty'])
                ->where('sampled_at', '<', $sampledAt)
                ->orderByDesc('sampled_at')
                ->orderByDesc('id')
                ->first();

            $snapshot = MikrotikBridgeHostSnapshot::create([
                'router_list_id' => $router->id,
                'sampled_at' => $sampledAt,
                'source' => 'routeros-api',
                'status' => $status,
                'entry_count' => count($rows),
                'dynamic_count' => $counts['dynamic'],
                'local_count' => $counts['local'],
                'external_count' => $counts['external'],
                'disabled_count' => $counts['disabled'],
                'invalid_count' => $counts['invalid'],
                'poll_duration_ms' => $duration,
                'error_message' => $error,
                'meta' => $meta,
            ]);

            if ($rows !== []) {
                $snapshot->entries()->createMany($rows);
            }

            if ($previousSnapshot && $status === 'ok') {
                $this->recordMovements($router, $previousSnapshot, $snapshot);
            }

            $retentionDays = max(1, min(365, (int) config('mikrotik_bridge_host.snapshot_retention_days', 30)));
            MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $router->id)
                ->where('sampled_at', '<', now()->subDays($retentionDays))
                ->delete();

            MikrotikBridgeHostMovement::query()
                ->where('router_list_id', $router->id)
                ->where('moved_at', '<', now()->subDays($retentionDays))
                ->delete();

            return $snapshot->load('entries');
        });
    }

    protected function fetchHosts(RouterList $router): array
    {
        if ($router->action !== 'connected') {
            throw new RuntimeException('Router is not marked as connected.');
        }

        if (! filled($router->ip_address) || ! filled($router->username) || ! filled($router->password) || ! filled($router->api_port)) {
            throw new RuntimeException('RouterOS API connection details are incomplete.');
        }

        $timeout = max(3, min(15, (int) config('mikrotik_bridge_host.timeout_seconds', 8)));
        $api = new MikrotikApiService(
            $router->ip_address,
            (int) $router->api_port,
            (string) $router->username,
            (string) $router->password,
            $timeout,
            (bool) $router->api_ssl
        );

        $bridgeHosts = $api->executeCommand('/interface/bridge/host/print');
        if (! is_array($bridgeHosts)) {
            throw new RuntimeException(is_string($bridgeHosts) ? $bridgeHosts : 'RouterOS Bridge Host query returned an invalid response.');
        }

        $pppoeSessions = $api->executeCommand('/ppp/active/print');
        if (! is_array($pppoeSessions)) {
            throw new RuntimeException(is_string($pppoeSessions) ? $pppoeSessions : 'RouterOS PPPoE active session query returned an invalid response.');
        }

        $pppoeInterfaces = $api->executeCommand('/interface/pppoe-server/print');
        $pppoeServers = $api->executeCommand('/interface/pppoe-server/server/print');
        $vlanInterfaces = $api->executeCommand('/interface/vlan/print');
        $pppoeInterfaces = is_array($pppoeInterfaces) ? array_values(array_filter($pppoeInterfaces, 'is_array')) : [];
        $pppoeServers = is_array($pppoeServers) ? array_values(array_filter($pppoeServers, 'is_array')) : [];
        $vlanInterfaces = is_array($vlanInterfaces) ? array_values(array_filter($vlanInterfaces, 'is_array')) : [];
        $this->collectionMetadata = [
            'pppoe_dynamic_interface_count' => count($pppoeInterfaces),
            'pppoe_server_count' => count($pppoeServers),
            'vlan_interface_count' => count($vlanInterfaces),
        ];

        return array_values(array_merge(
            $bridgeHosts,
            $this->normalizePppoeSessions($pppoeSessions, $pppoeInterfaces, $pppoeServers, $vlanInterfaces, $bridgeHosts)
        ));
    }

    protected function normalizePppoeSessions(
        array $sessions,
        array $pppoeInterfaces = [],
        array $pppoeServers = [],
        array $vlanInterfaces = [],
        array $bridgeHosts = []
    ): array {
        $vlanIdsByInterface = [];
        foreach ($vlanInterfaces as $vlanInterface) {
            if (! is_array($vlanInterface) || $this->flag($vlanInterface['disabled'] ?? false)) {
                continue;
            }

            $name = $this->nullableString($vlanInterface['name'] ?? null);
            $vlanId = $vlanInterface['vlan-id'] ?? null;
            if ($name === null || ! is_numeric($vlanId) || (int) $vlanId < 1 || (int) $vlanId > 4094) {
                continue;
            }

            $vlanIdsByInterface[$name][] = (int) $vlanId;
        }

        $serviceCandidates = [];
        $configuredServices = [];
        foreach ($pppoeServers as $server) {
            if (! is_array($server) || $this->flag($server['disabled'] ?? false)) {
                continue;
            }

            $service = $this->nullableString($server['service-name'] ?? null);
            $interface = $this->nullableString($server['interface'] ?? null);
            if ($service === null || $interface === null) {
                continue;
            }

            $configuredServices[$service] = true;
            $vlanIds = array_values(array_unique($vlanIdsByInterface[$interface] ?? []));
            $serviceCandidates[$service][] = [
                'vlan_id' => count($vlanIds) === 1 ? $vlanIds[0] : null,
                'interface' => $interface,
            ];
        }

        $serviceMappings = [];
        $ambiguousServices = [];
        foreach ($configuredServices as $service => $_configured) {
            $mappings = $serviceCandidates[$service] ?? [];
            $resolvedMappings = array_filter($mappings, static fn (array $mapping): bool => $mapping['vlan_id'] !== null);
            $vlanIds = array_values(array_unique(array_column($resolvedMappings, 'vlan_id')));
            if ($mappings === [] || count($resolvedMappings) !== count($mappings) || count($vlanIds) !== 1) {
                $ambiguousServices[$service] = true;
                continue;
            }

            $interfaces = array_values(array_unique(array_column($mappings, 'interface')));
            $serviceMappings[$service] = [
                'vlan_id' => $vlanIds[0],
                'interface' => count($interfaces) === 1 ? $interfaces[0] : null,
            ];
        }

        $dynamicByMac = [];
        foreach ($pppoeInterfaces as $pppoeInterface) {
            if (! is_array($pppoeInterface) || $this->flag($pppoeInterface['disabled'] ?? false)) {
                continue;
            }

            $mac = $this->normalizedMacAddress($pppoeInterface['remote-address'] ?? null);
            $service = $this->nullableString($pppoeInterface['service'] ?? null);
            if ($mac !== null && $service !== null) {
                $dynamicByMac[$mac][] = $pppoeInterface;
            }
        }

        $bridgeHostsByMac = [];
        foreach ($bridgeHosts as $bridgeHost) {
            if (! is_array($bridgeHost)) {
                continue;
            }

            $mac = $this->normalizedMacAddress($bridgeHost['mac-address'] ?? null);
            if ($mac !== null) {
                $bridgeHostsByMac[$mac][] = $bridgeHost;
            }
        }

        $hosts = [];
        foreach ($sessions as $session) {
            if (! is_array($session) || strcasecmp(trim((string) ($session['service'] ?? '')), 'pppoe') !== 0) {
                continue;
            }

            $mac = $this->normalizedMacAddress($session['caller-id'] ?? null);
            $dynamicMatches = $mac !== null ? ($dynamicByMac[$mac] ?? []) : [];
            $dynamicInterface = count($dynamicMatches) === 1 ? $dynamicMatches[0] : null;
            $service = $this->nullableString($dynamicInterface['service'] ?? null);
            $serviceMapping = $service !== null ? ($serviceMappings[$service] ?? null) : null;
            $bridgeMatches = $mac !== null ? ($bridgeHostsByMac[$mac] ?? []) : [];
            $bridgeHost = count($bridgeMatches) === 1 ? $bridgeMatches[0] : null;

            $bridgeHostVlan = $bridgeHost['vid'] ?? null;
            $bridgeHostVlan = is_numeric($bridgeHostVlan) && (int) $bridgeHostVlan >= 1 && (int) $bridgeHostVlan <= 4094
                ? (int) $bridgeHostVlan
                : null;
            $serviceVlan = $serviceMapping['vlan_id'] ?? null;
            $mappingConflict = $bridgeHostVlan !== null && $serviceVlan !== null && $bridgeHostVlan !== $serviceVlan;
            $vlanId = null;
            $mappingSource = null;
            if (! $mappingConflict && $bridgeHostVlan !== null) {
                $vlanId = $bridgeHostVlan;
                $mappingSource = 'bridge-host-vid';
            } elseif (! $mappingConflict && $serviceVlan !== null) {
                $vlanId = (int) $serviceVlan;
                $mappingSource = 'pppoe-server-vlan-interface';
            }

            $interface = $this->nullableString($bridgeHost['on-interface'] ?? $bridgeHost['interface'] ?? null)
                ?? $this->nullableString($serviceMapping['interface'] ?? null)
                ?? $this->nullableString($dynamicInterface['interface'] ?? null);
            $bridge = $this->nullableString($bridgeHost['bridge'] ?? null);
            $mappingAmbiguous = $mappingConflict
                || ($bridgeHostVlan === null && $service !== null && isset($ambiguousServices[$service]));

            $sessionId = $this->nullableString($session['.id'] ?? null)
                ?? Str::uuid()->toString();

            $hosts[] = [
                '.id' => 'pppoe:'.$sessionId,
                'mac-address' => $session['caller-id'] ?? null,
                'vid' => $vlanId,
                'bridge' => $bridge,
                'on-interface' => $interface,
                'dynamic' => 'true',
                'local' => 'false',
                'external' => 'false',
                'disabled' => 'false',
                'invalid' => 'false',
                '_source' => 'pppoe-caller-id',
                '_vlan_mapping_source' => $mappingSource,
                '_vlan_mapping_ambiguous' => $mappingAmbiguous,
            ];
        }

        return $hosts;
    }

    private function normalizedMacAddress(mixed $value): ?string
    {
        $hexMac = preg_replace('/[^0-9a-f]/i', '', (string) $value) ?? '';
        if (strlen($hexMac) !== 12) {
            return null;
        }

        return strtolower(implode(':', str_split($hexMac, 2)));
    }

    private function normalizeHosts(array $hosts): array
    {
        $rows = [];

        foreach ($hosts as $host) {
            if (! is_array($host)) {
                continue;
            }

            $hexMac = preg_replace('/[^0-9a-f]/i', '', (string) ($host['mac-address'] ?? '')) ?? '';
            if (strlen($hexMac) !== 12) {
                continue;
            }

            $vlanValue = $host['vid'] ?? null;
            $vlanId = is_numeric($vlanValue) && (int) $vlanValue >= 0 && (int) $vlanValue <= 4094
                ? (int) $vlanValue
                : null;

            $rows[] = [
                'router_entry_id' => isset($host['.id']) ? (string) $host['.id'] : null,
                'mac_address' => strtolower(implode(':', str_split($hexMac, 2))),
                'vlan_id' => $vlanId,
                'bridge_name' => $this->nullableString($host['bridge'] ?? null),
                'interface_name' => $this->nullableString($host['on-interface'] ?? $host['interface'] ?? null),
                'is_dynamic' => $this->flag($host['dynamic'] ?? false),
                'is_local' => $this->flag($host['local'] ?? false),
                'is_external' => $this->flag($host['external'] ?? false),
                'is_disabled' => $this->flag($host['disabled'] ?? false),
                'is_invalid' => $this->flag($host['invalid'] ?? false),
            ];
        }

        return $rows;
    }

    private function recordMovements(
        RouterList $router,
        MikrotikBridgeHostSnapshot $previousSnapshot,
        MikrotikBridgeHostSnapshot $snapshot
    ): void {
        $previous = $previousSnapshot->entries()
            ->where('is_dynamic', true)
            ->where('is_local', false)
            ->where('is_disabled', false)
            ->where('is_invalid', false)
            ->get()
            ->keyBy(fn (MikrotikBridgeHostEntry $entry): string => strtolower($entry->mac_address).'|'.($entry->vlan_id ?? ''));

        $current = $snapshot->entries()
            ->where('is_dynamic', true)
            ->where('is_local', false)
            ->where('is_disabled', false)
            ->where('is_invalid', false)
            ->get();

        foreach ($current as $entry) {
            $key = strtolower($entry->mac_address).'|'.($entry->vlan_id ?? '');
            $before = $previous->get($key);
            if (! $before) {
                continue;
            }

            $fromInterface = trim((string) $before->interface_name);
            $toInterface = trim((string) $entry->interface_name);
            $fromBridge = trim((string) $before->bridge_name);
            $toBridge = trim((string) $entry->bridge_name);
            $interfaceMoved = $fromInterface !== '' && $toInterface !== '' && strcasecmp($fromInterface, $toInterface) !== 0;
            $bridgeMoved = $fromBridge !== '' && $toBridge !== '' && strcasecmp($fromBridge, $toBridge) !== 0;

            if (! $interfaceMoved && ! $bridgeMoved) {
                continue;
            }

            $movement = MikrotikBridgeHostMovement::create([
                'router_list_id' => $router->id,
                'snapshot_id' => $snapshot->id,
                'previous_snapshot_id' => $previousSnapshot->id,
                'mac_address' => $entry->mac_address,
                'vlan_id' => $entry->vlan_id,
                'from_bridge' => $fromBridge !== '' ? $fromBridge : null,
                'to_bridge' => $toBridge !== '' ? $toBridge : null,
                'from_interface' => $fromInterface !== '' ? $fromInterface : null,
                'to_interface' => $toInterface !== '' ? $toInterface : null,
                'moved_at' => $snapshot->sampled_at,
                'moves_in_10m' => 1,
                'is_flapping' => false,
            ]);

            $recentMoves = MikrotikBridgeHostMovement::query()
                ->where('router_list_id', $router->id)
                ->where('mac_address', $entry->mac_address)
                ->where('vlan_id', $entry->vlan_id)
                ->where('moved_at', '>=', $snapshot->sampled_at->copy()->subMinutes(10))
                ->count();

            $movement->update([
                'moves_in_10m' => $recentMoves,
                'is_flapping' => $recentMoves >= 3,
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
