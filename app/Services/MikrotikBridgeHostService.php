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
    public function poll(RouterList $router): MikrotikBridgeHostSnapshot
    {
        $started = microtime(true);
        $sampledAt = now();
        $error = null;
        $hosts = [];

        try {
            $hosts = $this->fetchHosts($router);
        } catch (Throwable $exception) {
            $error = Str::limit($exception->getMessage(), 1500);
        }

        $maxEntries = max(100, min(50000, (int) config('mikrotik_bridge_host.max_entries', 10000)));
        $truncated = count($hosts) > $maxEntries;
        $rows = $error === null ? $this->normalizeHosts(array_slice($hosts, 0, $maxEntries)) : [];
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
            'api_command' => '/interface/bridge/host/print',
            'received_count' => count($hosts),
            'stored_count' => count($rows),
            'truncated' => $truncated,
        ];

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

        $response = $api->executeCommand('/interface/bridge/host/print');
        if (! is_array($response)) {
            throw new RuntimeException(is_string($response) ? $response : 'RouterOS Bridge Host query returned an invalid response.');
        }

        return array_values($response);
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
