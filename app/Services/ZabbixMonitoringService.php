<?php

namespace App\Services;

use App\Models\ZabbixServer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZabbixMonitoringService
{
    private function rpc(ZabbixServer $server, string $method, array $params, bool $authenticated = true): array
    {
        $request = Http::timeout(10)
            ->withOptions(['verify' => true, 'allow_redirects' => false])
            ->acceptJson()
            ->contentType('application/json');

        if ($authenticated) {
            $token = $server->api_token;
            if (! filled($token)) throw new RuntimeException('Zabbix API token could not be decrypted.');
            $request = $request->withToken($token);
        }

        $url = rtrim(trim($server->api_url), '/');
        if (! str_ends_with(strtolower($url), '/api_jsonrpc.php')) $url .= '/api_jsonrpc.php';

        $response = $request->post($url, [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zabbix API returned HTTP '.$response->status().'.');
        }

        $body = $response->json();
        if (! is_array($body)) throw new RuntimeException('Zabbix API returned invalid JSON.');
        if (isset($body['error'])) {
            throw new RuntimeException((string) ($body['error']['data'] ?? $body['error']['message'] ?? 'Zabbix API error.'));
        }
        if (! array_key_exists('result', $body)) {
            throw new RuntimeException('Zabbix API response did not contain a result.');
        }

        return $body;
    }

    public function syncServer(ZabbixServer $server): array
    {
        try {
            $version = $this->rpc($server, 'apiinfo.version', [], false);
            $hosts = $this->rpc($server, 'host.get', ['countOutput' => true]);
            // problem.get returns unresolved problems by default. Do not use "recent",
            // which would also count recently resolved alarms.
            $problems = $this->rpc($server, 'problem.get', ['countOutput' => true]);

            $hostCount = $this->countResult($hosts['result']);
            $problemCount = $this->countResult($problems['result']);
            $apiVersion = is_scalar($version['result']) ? (string) $version['result'] : 'unknown';

            $server->forceFill([
                'api_version' => mb_substr($apiVersion, 0, 40),
                'host_count' => $hostCount,
                'problem_count' => $problemCount,
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'server_id' => $server->id,
                'version' => $apiVersion,
                'hosts' => $hostCount,
                'active_problems' => $problemCount,
                'status' => 'ok',
            ];
        } catch (\Throwable $exception) {
            $server->forceFill([
                'last_checked_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }
    }

    public function syncAll(): array
    {
        $servers = ZabbixServer::query()->where('is_active', true)->orderBy('id')->get();
        $results = [];
        $errors = [];

        foreach ($servers as $server) {
            try {
                $results[] = $this->syncServer($server);
            } catch (\Throwable $exception) {
                $errors[] = $server->name.': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                count($errors).' of '.count($servers).' Zabbix servers failed to sync: '.implode(' | ', $errors)
            );
        }

        return [
            'configured' => $servers->count(),
            'synced' => count($results),
            'servers' => $results,
        ];
    }

    public function hosts(ZabbixServer $server, int $limit = 200): array
    {
        $response = $this->rpc($server, 'host.get', [
            'output' => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'dns', 'main', 'useip'],
            'selectGroups' => ['name'],
            'sortfield' => 'name',
            'sortorder' => 'ASC',
            'limit' => min(500, max(1, $limit)),
        ]);

        if (! is_array($response['result'])) return [];

        return collect($response['result'])->filter(fn ($host) => is_array($host))
            ->map(function (array $host): array {
                $interfaces = is_array($host['interfaces'] ?? null) ? $host['interfaces'] : [];
                $mainInterface = collect($interfaces)->first(fn ($interface) => (string) ($interface['main'] ?? '0') === '1')
                    ?? ($interfaces[0] ?? []);
                $useIp = (string) ($mainInterface['useip'] ?? '1') === '1';
                $address = $useIp
                    ? (string) ($mainInterface['ip'] ?? '')
                    : (string) ($mainInterface['dns'] ?? '');
                if ($address === '') $address = (string) ($mainInterface['ip'] ?? $mainInterface['dns'] ?? '');

                return [
                    'hostid' => (string) ($host['hostid'] ?? ''),
                    'host' => (string) ($host['host'] ?? ''),
                    'name' => (string) ($host['name'] ?? $host['host'] ?? 'Unknown host'),
                    'status' => (string) ($host['status'] ?? '0') === '1' ? 'disabled' : 'enabled',
                    'address' => $address,
                    'groups' => collect($host['groups'] ?? [])->pluck('name')->filter()->values()->all(),
                ];
            })->values()->all();
    }

    public function problems(ZabbixServer $server, int $limit = 100): array
    {
        $response = $this->rpc($server, 'problem.get', [
            'output' => ['eventid', 'name', 'severity', 'clock', 'r_clock'],
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
            'limit' => min(500, max(1, $limit)),
        ]);

        return is_array($response['result']) ? $response['result'] : [];
    }

    private function countResult(mixed $result): int
    {
        if (is_int($result) || (is_string($result) && ctype_digit($result))) {
            return max(0, (int) $result);
        }

        // Compatibility fallback for Zabbix versions that return arrays despite
        // countOutput; counts are exact only up to the configured API response.
        return is_array($result) ? count($result) : 0;
    }
}
