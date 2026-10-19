<?php

namespace App\Livewire;

use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkInventoryHealthCheck;
use App\Models\OltOnuCustomerMapping;
use App\Models\RouterList;
use Livewire\Component;

class DeviceDetail extends Component
{
    public string $kind = '';
    public int $deviceId = 0;
    public string $message = '';

    public function mount(string $kind, int $device): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) abort(403);
        $this->kind = strtolower($kind);
        abort_unless(in_array($this->kind, ['mikrotik', 'olt', 'device'], true), 404);
        $this->deviceId = $device;
        if ($this->kind === 'olt') {
            abort_unless(NetworkInventoryDevice::whereKey($device)->where('type', 'olt')->exists(), 404);
        } elseif ($this->kind === 'device') {
            abort_unless(NetworkInventoryDevice::whereKey($device)->where('type', '!=', 'olt')->exists(), 404);
        }
    }

    public function probe(): void
    {
        if ($this->kind === 'mikrotik') {
            $router = RouterList::findOrFail($this->deviceId);
            $this->socketProbe($router->ip_address, (int) ($router->api_port ?: 8728), function ($ok, $latency, $error) use ($router) {
                $router->update(['action' => $ok ? 'connected' : 'disconnected', 'last_latency_ms' => $latency, 'last_checked_at' => now()]);
                $this->syncRouterAlert($router, $ok, $latency, $error);
            });
            return;
        }
        $device = NetworkInventoryDevice::findOrFail($this->deviceId);
        if ($device->type === 'olt') {
            $probe = app(\App\Services\NetworkInventoryHealthProbe::class)->check($device);
            $probeStatus = (string) ($probe['status'] ?? 'down');
            $ok = $probeStatus === 'online';
            $latency = (int) ($probe['latency_ms'] ?? 0);
            $checkedAt = now();
            $healthStatus = $probeStatus === 'online' ? 'ready' : ($probeStatus === 'not_ready' ? 'unknown' : 'failed');
            $deviceStatus = $probeStatus === 'online' ? 'online' : ($probeStatus === 'not_ready' ? 'unknown' : 'offline');
            $device->update(['status'=>$deviceStatus,'health_status'=>$healthStatus,'last_latency_ms'=>$latency,'last_checked_at'=>$checkedAt]);
            NetworkInventoryHealthCheck::create([
                'network_inventory_device_id'=>$device->id,
                'status'=>$probeStatus === 'online' ? 'online' : ($probeStatus === 'not_ready' ? 'unknown' : 'down'),
                'latency_ms'=>$latency,
                'checked_at'=>$checkedAt,
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
        $this->socketProbe($host, $port, function ($ok, $latency, $error) use ($device) {
            $checkedAt = now();
            $device->update(['status' => $ok ? 'online' : 'offline','health_status' => $ok ? 'ready' : 'failed','last_latency_ms' => $latency,'last_checked_at' => $checkedAt]);
            NetworkInventoryHealthCheck::create(['network_inventory_device_id'=>$device->id,'status'=>$ok?'online':'down','latency_ms'=>$latency,'checked_at'=>$checkedAt]);
            $this->syncDeviceAlert($device, $ok, $latency, $error, $checkedAt);
        });
    }

    private function socketProbe(?string $host, int $port, callable $after): void
    {
        if (!$host) { $this->message='Management host is not configured.'; return; }
        $started=microtime(true); $errno=0; $error=''; $socket=@fsockopen($host,$port,$errno,$error,3);
        $latency=(int) round((microtime(true)-$started)*1000); $ok=$socket!==false;
        if ($socket!==false) fclose($socket);
        $after($ok, $latency, $error ?: null);
        $this->message=($ok?'Connectivity OK':'Device unreachable').' · '.$latency.' ms'.($ok?'':' · '.($error?:'connection refused'));
    }

    public function acknowledge(int $eventId): void
    {
        $event = $this->currentEvent($eventId, ['open']);
        $event->update(['status'=>'acknowledged','acknowledged_by'=>auth()->id(),'acknowledged_at'=>now()]);
        $this->message = 'Alert acknowledged.';
    }

    public function resolve(int $eventId): void
    {
        $event = $this->currentEvent($eventId, ['open', 'acknowledged']);
        $event->update(['status'=>'resolved','resolved_by'=>auth()->id(),'resolved_at'=>now()]);
        $this->message = 'Alert resolved.';
    }


    private function currentEvent(int $eventId, ?array $statuses = null): NetworkEvent
    {
        $query = NetworkEvent::query()->whereKey($eventId);
        if ($this->kind === 'mikrotik') {
            $query->whereNull('device_id')->where('metadata->router_id', $this->deviceId);
        } else {
            $query->where('device_id', $this->deviceId);
        }
        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }
        return $query->firstOrFail();
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
                NetworkEvent::create(['device_id'=>$device->id,'source_type'=>'manual-probe','event_type'=>'device_down','severity'=>'critical','title'=>'Device unreachable: '.$device->name,'message'=>'Management connectivity failed. '.($error ?: 'Connection failed').' · '.$latency.' ms','status'=>'open','fingerprint'=>$fingerprint,'occurrences'=>1,'first_seen_at'=>$checkedAt,'last_seen_at'=>$checkedAt,'metadata'=>['ip_address'=>$device->ip_address ?: $device->host,'port'=>$device->type === 'olt' ? ($device->snmp_port ?: 161) : ($device->health_port ?: $device->port),'protocol'=>$device->type === 'olt' ? 'snmp' : 'tcp']]);
            }
        } elseif ($open) {
            $open->update(['status'=>'resolved','resolved_by'=>null,'resolved_at'=>$checkedAt,'last_seen_at'=>$checkedAt,'message'=>'Device recovered. Connectivity is healthy at '.$latency.' ms.']);
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
                $open->update(['last_seen_at'=>$checkedAt,'message'=>'Router API remains unreachable. '.($error ?: 'Connection failed').' · '.$latency.' ms']);
            } else {
                NetworkEvent::create(['device_id'=>null,'source_type'=>'manual-probe','event_type'=>'router_down','severity'=>'critical','title'=>'Router unreachable: '.$router->router_name,'message'=>'Router management API did not answer. '.($error ?: 'Connection failed').' · '.$latency.' ms','status'=>'open','fingerprint'=>$fingerprint,'occurrences'=>1,'first_seen_at'=>$checkedAt,'last_seen_at'=>$checkedAt,'metadata'=>['router_id'=>$router->id,'ip_address'=>$router->ip_address,'port'=>$router->api_port]]);
            }
        } elseif ($open) {
            $open->update(['status'=>'resolved','resolved_by'=>null,'resolved_at'=>$checkedAt,'last_seen_at'=>$checkedAt,'message'=>'Router API recovered at '.$latency.' ms.']);
        }
    }

    public function render()
    {
        $router = $this->kind === 'mikrotik' ? RouterList::findOrFail($this->deviceId) : null;
        $device = $this->kind === 'mikrotik' ? null : NetworkInventoryDevice::findOrFail($this->deviceId);
        $name = $router?->router_name ?: $device?->name;
        $events = NetworkEvent::query()
            ->when($device, fn($q)=>$q->where('device_id',$device->id))
            ->when($router, fn($q)=>$q->whereNull('device_id')->where('metadata->router_id',$router->id))
            ->with(['acknowledgedBy', 'resolvedBy'])->latest('last_seen_at')->limit(15)->get();
        $health = $device ? NetworkInventoryHealthCheck::where('network_inventory_device_id',$device->id)->latest('checked_at')->limit(24)->get() : collect();
        $mappings = $device && $device->type === 'olt' ? OltOnuCustomerMapping::with(['customer','pppUser'])->where('olt_device_id',$device->id)->latest('last_seen_at')->limit(100)->get() : collect();
        $openQuery = NetworkEvent::query()->whereIn('status', ['open', 'acknowledged']);
        if ($device) {
            $openQuery->where('device_id', $device->id);
        } else {
            $openQuery->whereNull('device_id')->where('metadata->router_id', $router->id);
        }
        $openEvents = $openQuery->count();
        $mappingTotal = $device && $device->type === 'olt' ? OltOnuCustomerMapping::where('olt_device_id', $device->id)->count() : 0;
        $mappingOnline = $device && $device->type === 'olt' ? OltOnuCustomerMapping::where('olt_device_id', $device->id)->where('status', 'online')->count() : 0;
        return view('livewire.device-detail', compact('router','device','name','events','health','mappings','openEvents','mappingTotal','mappingOnline'))->layout('layouts.app');
    }
}
