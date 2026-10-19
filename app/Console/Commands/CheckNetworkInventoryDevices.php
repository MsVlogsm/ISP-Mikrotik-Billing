<?php

namespace App\Console\Commands;

use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkInventoryHealthCheck;
use App\Services\NetworkInventoryHealthProbe;
use Illuminate\Console\Command;

class CheckNetworkInventoryDevices extends Command
{
    protected $signature = 'app:check-network-inventory';
    protected $description = 'Check monitored network inventory devices, persist health history and maintain alerts.';

    public function handle(): int
    {
        NetworkInventoryDevice::query()
            ->where('monitor_enabled', true)
            ->where(function ($query) {
                $query->whereNotNull('ip_address')->orWhereNotNull('host');
            })
            ->chunkById(100, function ($devices) {
                foreach ($devices as $device) {
                    $checkedAt = now();
                    $previous = $device->health_status ?: $device->status;
                    $probe = (new NetworkInventoryHealthProbe())->check($device);
                    $latency = (int) $probe['latency_ms'];
                    $status = $probe['status'] === 'online' ? 'online' : ($probe['status'] === 'not_ready' ? 'unknown' : 'down');
                    $errorMessage = $probe['message'];

                    $device->update([
                        'health_status' => $status,
                        'status' => $status === 'online' ? 'online' : ($status === 'unknown' ? 'unknown' : 'offline'),
                        'last_latency_ms' => $latency,
                        'last_checked_at' => $checkedAt,
                    ]);

                    NetworkInventoryHealthCheck::create([
                        'network_inventory_device_id' => $device->id,
                        'status' => $status,
                        'latency_ms' => $latency,
                        'checked_at' => $checkedAt,
                    ]);

                    $this->syncDeviceAlert($device, $status, $previous, $latency, $errorMessage ?? null, $checkedAt);
                }
            });

        return self::SUCCESS;
    }

    private function syncDeviceAlert(NetworkInventoryDevice $device, string $status, ?string $previous, int $latency, ?string $error, $checkedAt): void
    {
        $fingerprint = 'device-down:'.$device->id;
        $open = NetworkEvent::where('fingerprint', $fingerprint)->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();

        if ($status === 'down') {
            if ($open) {
                $open->increment('occurrences');
                $open->update(['last_seen_at' => $checkedAt, 'message' => 'Device remains unreachable. '.($error ?: 'Connection failed').' · '.$latency.' ms']);
                return;
            }

            NetworkEvent::create([
                'device_id' => $device->id,
                'source_type' => 'network-monitoring',
                'event_type' => 'device_down',
                'severity' => 'critical',
                'title' => 'Device unreachable: '.$device->name,
                'message' => 'Management connectivity failed. '.($error ?: 'Connection failed').' · '.$latency.' ms',
                'status' => 'open',
                'fingerprint' => $fingerprint,
                'occurrences' => 1,
                'first_seen_at' => $checkedAt,
                'last_seen_at' => $checkedAt,
                'metadata' => ['ip_address' => $device->ip_address ?: $device->host, 'port' => $device->health_port ?: $device->port, 'protocol' => $probe['protocol'] ?? 'tcp', 'previous_status' => $previous],
            ]);
            return;
        }

        if ($status === 'unknown') return;

        if ($open) {
            $open->update([
                'status' => 'resolved',
                'resolved_by' => null,
                'resolved_at' => $checkedAt,
                'last_seen_at' => $checkedAt,
                'message' => 'Device recovered. Connectivity is healthy at '.$latency.' ms.',
            ]);
        }
    }
}
