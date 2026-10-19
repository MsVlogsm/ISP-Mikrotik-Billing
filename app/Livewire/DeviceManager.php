<?php

namespace App\Livewire;

use App\Models\CustomersInfo;
use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkInventoryHealthCheck;
use App\Models\RouterList;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DeviceManager extends Component
{
    public string $search = '';
    public string $type = '';
    public string $status = '';
    public string $location = '';
    public string $message = '';
    public bool $showAddDevice = false;
    public string $newDeviceType = 'switch';
    public string $newDeviceName = '';
    public string $newDeviceIp = '';
    public string $newDeviceVendor = '';
    public string $newDeviceModel = '';
    public string $newDevicePort = '80';
    public string $newDeviceLocation = '';
    public bool $newDeviceMonitorEnabled = true;

    public function mount(): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) {
            abort(403);
        }
    }

    public function refreshBoard(): void
    {
        $this->message = 'Board refreshed at '.now()->format('d M Y H:i:s').'. Snapshots are refreshed by the scheduled monitor; use Probe for a single device.';
    }

    public function createDevice(): void
    {
        if (! hasAccess(['Super Admin'], ['network-inventory'])) {
            abort(403);
        }

        $data = $this->validate([
            'newDeviceType' => ['required', Rule::in(['switch', 'access-point'])],
            'newDeviceName' => ['required', 'string', 'max:120'],
            'newDeviceIp' => ['nullable', 'ip'],
            'newDeviceVendor' => ['nullable', 'string', 'max:80'],
            'newDeviceModel' => ['nullable', 'string', 'max:120'],
            'newDevicePort' => ['required', 'integer', 'min:1', 'max:65535'],
            'newDeviceLocation' => ['nullable', 'string', 'max:160'],
            'newDeviceMonitorEnabled' => ['boolean'],
        ]);

        NetworkInventoryDevice::create([
            'type' => $data['newDeviceType'],
            'name' => trim($data['newDeviceName']),
            'ip_address' => $data['newDeviceIp'] ?: null,
            'vendor' => $data['newDeviceVendor'] ?: null,
            'model' => $data['newDeviceModel'] ?: null,
            'health_port' => (int) $data['newDevicePort'],
            'port' => (int) $data['newDevicePort'],
            'location' => $data['newDeviceLocation'] ?: null,
            'status' => 'unknown',
            'health_status' => 'unknown',
            'monitor_enabled' => (bool) $data['newDeviceMonitorEnabled'],
            'is_active' => true,
        ]);

        $this->reset(['newDeviceName', 'newDeviceIp', 'newDeviceVendor', 'newDeviceModel', 'newDeviceLocation']);
        $this->newDeviceType = 'switch';
        $this->newDevicePort = '80';
        $this->newDeviceMonitorEnabled = true;
        $this->showAddDevice = false;
        $this->message = 'Device added to Network Inventory.';
    }

    public function probeRouter(int $id): void
    {
        $router = RouterList::findOrFail($id);
        $this->socketProbe($router->ip_address, (int) ($router->api_port ?: 8728), 'MikroTik', $router->router_name,
            function (bool $ok, int $latency, ?string $error) use ($router): void {
                $router->update([
                    'action' => $ok ? 'connected' : 'disconnected',
                    'last_latency_ms' => $latency,
                    'last_checked_at' => now(),
                ]);
                $this->syncRouterAlert($router, $ok, $latency, $error);
            });
    }

    public function probeDevice(int $id): void
    {
        $device = NetworkInventoryDevice::findOrFail($id);

        if ($device->type === 'olt') {
            $probe = app(\App\Services\NetworkInventoryHealthProbe::class)->check($device);
            $probeStatus = (string) ($probe['status'] ?? 'down');
            $ok = $probeStatus === 'online';
            $latency = (int) ($probe['latency_ms'] ?? 0);
            $checkedAt = now();
            $healthStatus = $probeStatus === 'online' ? 'ready' : ($probeStatus === 'not_ready' ? 'unknown' : 'failed');
            $deviceStatus = $probeStatus === 'online' ? 'online' : ($probeStatus === 'not_ready' ? 'unknown' : 'offline');
            $device->update([
                'status' => $deviceStatus,
                'health_status' => $healthStatus,
                'last_latency_ms' => $latency,
                'last_checked_at' => $checkedAt,
            ]);
            NetworkInventoryHealthCheck::create([
                'network_inventory_device_id' => $device->id,
                'status' => $probeStatus === 'online' ? 'online' : ($probeStatus === 'not_ready' ? 'unknown' : 'down'),
                'latency_ms' => $latency,
                'checked_at' => $checkedAt,
            ]);
            if ($probeStatus !== 'not_ready') {
                $this->syncDeviceAlert($device, $ok, $latency, (string) ($probe['message'] ?? ''), $checkedAt);
            }
            $this->message = 'OLT SNMP check '.($probeStatus === 'online' ? 'passed' : ($probeStatus === 'not_ready' ? 'not ready' : 'failed'))
                .' on '.($device->ip_address ?: $device->host).':'.(int) ($probe['port'] ?? ($device->snmp_port ?: 161))
                .' — '.(string) ($probe['message'] ?? 'No probe details available.');
            return;
        }

        $host = $device->ip_address ?: $device->host;
        $port = (int) ($device->health_port ?: $device->port ?: 80);
        $this->socketProbe($host, $port, strtoupper(str_replace('-', ' ', $device->type)), $device->name,
            function (bool $ok, int $latency, ?string $error) use ($device): void {
                $checkedAt = now();
                $device->update([
                    'status' => $ok ? 'online' : 'offline',
                    'health_status' => $ok ? 'ready' : 'failed',
                    'last_latency_ms' => $latency,
                    'last_checked_at' => $checkedAt,
                ]);
                NetworkInventoryHealthCheck::create([
                    'network_inventory_device_id' => $device->id,
                    'status' => $ok ? 'online' : 'down',
                    'latency_ms' => $latency,
                    'checked_at' => $checkedAt,
                ]);
                $this->syncDeviceAlert($device, $ok, $latency, $error, $checkedAt);
            });
    }

    private function socketProbe(?string $host, int $port, string $kind, string $name, callable $after): void
    {
        if (! $host) {
            $this->message = $name.' has no management IP/host configured.';
            return;
        }

        $started = microtime(true);
        $errno = 0;
        $error = '';
        $socket = @fsockopen($host, $port, $errno, $error, 3);
        $latency = (int) round((microtime(true) - $started) * 1000);
        $ok = $socket !== false;
        if ($socket !== false) {
            fclose($socket);
        }

        $after($ok, $latency, $error ?: null);
        $this->message = $kind.' '.$name.' '.($ok ? 'reachable' : 'unreachable').' · '.$latency.' ms'.($ok ? '' : ' · '.($error ?: 'connection failed'));
    }

    private function syncDeviceAlert(NetworkInventoryDevice $device, bool $ok, int $latency, ?string $error, $checkedAt): void
    {
        $fingerprint = 'device-down:'.$device->id;
        $open = NetworkEvent::where('fingerprint', $fingerprint)->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();

        if (! $ok) {
            if ($open) {
                $open->increment('occurrences');
                $open->update(['last_seen_at' => $checkedAt, 'message' => 'Device remains unreachable. '.($error ?: 'Connection failed').' · '.$latency.' ms']);
            } else {
                NetworkEvent::create([
                    'device_id' => $device->id, 'source_type' => 'manual-probe', 'event_type' => 'device_down',
                    'severity' => 'critical', 'title' => 'Device unreachable: '.$device->name,
                    'message' => 'Management connectivity failed. '.($error ?: 'Connection failed').' · '.$latency.' ms',
                    'status' => 'open', 'fingerprint' => $fingerprint, 'occurrences' => 1,
                    'first_seen_at' => $checkedAt, 'last_seen_at' => $checkedAt,
                    'metadata' => ['ip_address' => $device->ip_address ?: $device->host, 'port' => $device->type === 'olt' ? ($device->snmp_port ?: 161) : ($device->health_port ?: $device->port), 'protocol' => $device->type === 'olt' ? 'snmp' : 'tcp'],
                ]);
            }
        } elseif ($open) {
            $open->update(['status' => 'resolved', 'resolved_by' => null, 'resolved_at' => $checkedAt, 'last_seen_at' => $checkedAt, 'message' => 'Device recovered. Connectivity is healthy at '.$latency.' ms.']);
        }
    }

    private function syncRouterAlert(RouterList $router, bool $ok, int $latency, ?string $error): void
    {
        $checkedAt = now();
        $fingerprint = 'router-down:'.$router->id;
        $open = NetworkEvent::where('fingerprint', $fingerprint)->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();

        if (! $ok) {
            if ($open) {
                $open->increment('occurrences');
                $open->update(['last_seen_at' => $checkedAt, 'message' => 'Router API unreachable. '.($error ?: 'Connection failed').' · '.$latency.' ms']);
            } else {
                NetworkEvent::create([
                    'device_id' => null, 'source_type' => 'manual-probe', 'event_type' => 'router_down',
                    'severity' => 'critical', 'title' => 'Router unreachable: '.$router->router_name,
                    'message' => 'Router management API did not answer. '.($error ?: 'Connection failed').' · '.$latency.' ms',
                    'status' => 'open', 'fingerprint' => $fingerprint, 'occurrences' => 1,
                    'first_seen_at' => $checkedAt, 'last_seen_at' => $checkedAt,
                    'metadata' => ['router_id' => $router->id, 'ip_address' => $router->ip_address, 'port' => $router->api_port],
                ]);
            }
        } elseif ($open) {
            $open->update(['status' => 'resolved', 'resolved_by' => null, 'resolved_at' => $checkedAt, 'last_seen_at' => $checkedAt, 'message' => 'Router API recovered at '.$latency.' ms.']);
        }
    }

    public function render()
    {
        $routerEvents = NetworkEvent::whereNull('device_id')->whereIn('status', ['open', 'acknowledged'])->get();
        $deviceEventCounts = NetworkEvent::query()
            ->selectRaw('device_id, COUNT(*) as aggregate')
            ->whereNotNull('device_id')->whereIn('status', ['open', 'acknowledged'])
            ->groupBy('device_id')->pluck('aggregate', 'device_id');

        $routers = RouterList::query()->orderBy('router_name')->get()->map(function ($r) use ($routerEvents) {
            $row = [
                'id' => $r->id, 'name' => $r->router_name, 'type' => 'mikrotik', 'type_label' => 'MikroTik',
                'vendor' => 'MikroTik', 'model' => $r->model ?: 'RouterOS', 'host' => $r->ip_address,
                'port' => (int) ($r->api_port ?: 8728), 'protocol' => 'MikroTik API',
                'status' => $r->action === 'connected' ? 'online' : (in_array($r->action, ['disconnected', 'offline'], true) ? 'offline' : 'unknown'),
                'location' => $r->location ?: '—', 'latency' => $r->last_latency_ms,
                'seen' => $r->last_checked_at ?: $r->updated_at,
                'url' => route('device-manager.detail', ['kind' => 'mikrotik', 'device' => $r->id]),
                'alerts' => $routerEvents->filter(fn ($e) => (int) data_get($e->metadata, 'router_id') === (int) $r->id)->count(),
            ];
            return $row;
        });

        $devices = NetworkInventoryDevice::query()->orderBy('name')->get()->map(function ($d) use ($deviceEventCounts) {
            $health = strtolower(str_replace([' ', '-'], '_', (string) $d->health_status));
            $raw = in_array($health, ['', 'unknown', 'null'], true)
                ? strtolower(str_replace([' ', '-'], '_', (string) $d->status))
                : $health;
            $status = match ($raw) {
                'ready', 'online', 'connected', 'up' => 'online',
                'failed', 'down', 'offline', 'unreachable' => 'offline',
                'authentication_failed', 'credentials_missing', 'api_error', 'degraded' => $raw,
                default => 'unknown',
            };
            $port = $d->type === 'olt' ? (int) ($d->snmp_port ?: 161) : (int) ($d->health_port ?: $d->port ?: 80);
            return [
                'id' => $d->id, 'name' => $d->name, 'type' => strtolower($d->type),
                'type_label' => ucfirst(str_replace('-', ' ', $d->type)), 'vendor' => $d->vendor ?: '—',
                'model' => $d->model ?: '—', 'host' => $d->ip_address ?: $d->host, 'port' => $port,
                'protocol' => $d->type === 'olt' ? 'SNMP/UDP' : strtoupper((string) ($d->web_protocol ?: 'tcp')),
                'status' => $status, 'location' => $d->location ?: '—', 'latency' => $d->last_latency_ms,
                'seen' => $d->last_checked_at, 'alerts' => (int) ($deviceEventCounts[$d->id] ?? 0),
                'url' => route('device-manager.detail', ['kind' => $d->type === 'olt' ? 'olt' : 'device', 'device' => $d->id]),
            ];
        });

        $allRows = $routers->concat($devices)->values();
        $q = mb_strtolower(trim($this->search));
        $rows = $allRows->filter(function ($r) use ($q) {
            if ($this->type !== '' && strtolower($r['type']) !== strtolower($this->type)) return false;
            if ($this->status !== '' && $r['status'] !== $this->status) return false;
            if ($this->location !== '' && $r['location'] !== $this->location) return false;
            if ($q === '') return true;
            return str_contains(mb_strtolower((string) $r['name']), $q)
                || str_contains(mb_strtolower((string) $r['host']), $q)
                || str_contains(mb_strtolower((string) $r['vendor']), $q)
                || str_contains(mb_strtolower((string) $r['model']), $q);
        })->values();

        $total = $allRows->count();
        $online = $allRows->where('status', 'online')->count();
        $attention = $allRows->whereIn('status', ['offline', 'authentication_failed', 'credentials_missing', 'api_error', 'degraded'])->count();
        $criticalAlerts = NetworkEvent::whereIn('status', ['open', 'acknowledged'])->where('severity', 'critical')->count();
        $latencies = $allRows->pluck('latency')->filter(fn ($v) => is_numeric($v));
        $avgLatency = $latencies->count() ? round($latencies->avg(), 2) : null;
        $customerCount = (int) CustomersInfo::count();
        $activeCustomerCount = (int) CustomersInfo::where('status', 'active')->count();
        $inactiveCustomerCount = max(0, $customerCount - $activeCustomerCount);
        $locations = $allRows->pluck('location')->filter(fn ($v) => $v && $v !== '—')->unique()->sort()->values();
        $types = $allRows->pluck('type')->unique()->sort()->values();

        return view('livewire.device-manager', compact('rows', 'total', 'online', 'attention', 'criticalAlerts', 'avgLatency', 'customerCount', 'activeCustomerCount', 'inactiveCustomerCount', 'locations', 'types'))
            ->layout('layouts.app');
    }
}
