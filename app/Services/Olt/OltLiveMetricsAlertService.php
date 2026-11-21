<?php

namespace App\Services\Olt;

use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\OltLiveMetricPort;
use App\Models\OltLiveMetricSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class OltLiveMetricsAlertService
{
    public function sync(NetworkInventoryDevice $device, OltLiveMetricSnapshot $snapshot): void
    {
        $this->resolve($device, 'olt-live:poll-failed:'.$device->id, 'A successful read-only telemetry poll has recovered.');

        $thresholds = (array) config('olt_dashboard.live_alerts', []);

        $this->syncUpperBound(
            $device,
            'cpu',
            'olt_cpu_high',
            'OLT CPU utilization is high: '.$device->name,
            $snapshot->cpu_percent,
            (array) ($thresholds['cpu_percent'] ?? ['warning' => 85, 'critical' => 95]),
            '%'
        );

        $this->syncUpperBound(
            $device,
            'memory',
            'olt_memory_high',
            'OLT memory utilization is high: '.$device->name,
            $snapshot->memory_percent,
            (array) ($thresholds['memory_percent'] ?? ['warning' => 90, 'critical' => 97]),
            '%'
        );

        $this->syncConfiguredTemperature($device, $snapshot);

        $ports = $snapshot->ports instanceof Collection ? $snapshot->ports : $snapshot->ports()->get();
        $uplinks = $ports->filter(fn (OltLiveMetricPort $port) => str_starts_with((string) $port->role, 'uplink_'));
        $allUplinksDown = $uplinks->isNotEmpty() && $uplinks->every(fn (OltLiveMetricPort $port) => $port->oper_status === 'down');

        foreach ($ports as $port) {
            if ($port->role === 'pon') {
                $fingerprint = 'olt-live:pon-down:'.$device->id.':'.$port->if_index;
                if ($port->oper_status === 'down' && (int) $port->onu_total > 0) {
                    $this->raise(
                        $device,
                        $fingerprint,
                        'olt_pon_down',
                        'PON port is down: '.$device->name.' / '.$port->interface_name,
                        'PON interface reports down while '.$port->onu_total.' ONU(s) are present.',
                        'critical',
                        ['if_index' => (int) $port->if_index, 'interface' => (string) $port->interface_name, 'onu_total' => (int) $port->onu_total]
                    );
                } elseif ($port->oper_status === 'up' || ($port->oper_status === 'down' && (int) $port->onu_total === 0)) {
                    $this->resolve($device, $fingerprint, 'PON port is no longer carrying an affected ONU population.');
                }

                if ((int) $port->rx_sample_count > 0 && $port->avg_rx_dbm !== null) {
                    $this->syncLowerBound(
                        $device,
                        'optical-rx:'.$port->if_index,
                        'olt_optical_rx_low',
                        'Weak ONU optical RX on '.$device->name.' / '.$port->interface_name,
                        $port->avg_rx_dbm,
                        (array) ($thresholds['onu_rx_dbm'] ?? ['warning' => -20, 'critical' => -27]),
                        'dBm',
                        ['if_index' => (int) $port->if_index, 'interface' => (string) $port->interface_name, 'sample_count' => (int) $port->rx_sample_count]
                    );
                }
            }

            if (str_starts_with((string) $port->role, 'uplink_')) {
                $fingerprint = 'olt-live:uplink-down:'.$device->id.':'.$port->if_index;
                if ($port->oper_status === 'down') {
                    $this->raise(
                        $device,
                        $fingerprint,
                        'olt_uplink_down',
                        'OLT uplink is down: '.$device->name.' / '.$port->interface_name,
                        'IF-MIB reports this uplink interface is down.',
                        $allUplinksDown ? 'critical' : 'warning',
                        ['if_index' => (int) $port->if_index, 'interface' => (string) $port->interface_name, 'all_uplinks_down' => $allUplinksDown]
                    );
                } elseif ($port->oper_status === 'up') {
                    $this->resolve($device, $fingerprint, 'Uplink interface recovered according to IF-MIB.');
                }
            }
        }
    }

    public function pollFailed(NetworkInventoryDevice $device, Throwable $exception): void
    {
        $class = class_basename($exception);
        $this->raise(
            $device,
            'olt-live:poll-failed:'.$device->id,
            'olt_telemetry_poll_failed',
            'OLT telemetry poll failed: '.$device->name,
            'The scheduled read-only telemetry poll did not complete ('.$class.'). Check the OLT management path and SNMP profile.',
            'warning',
            ['exception_class' => $class]
        );
    }

    private function syncConfiguredTemperature(NetworkInventoryDevice $device, OltLiveMetricSnapshot $snapshot): void
    {
        $config = $device->adapter_config;
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        $config = is_array($config) ? $config : [];
        $temperature = data_get($config, 'alert_thresholds.temperature_c');

        if (! is_array($temperature) || ! is_numeric($temperature['warning'] ?? null) || ! is_numeric($temperature['critical'] ?? null)) {
            return;
        }

        $this->syncUpperBound(
            $device,
            'temperature',
            'olt_temperature_high',
            'OLT chassis temperature is high: '.$device->name,
            $snapshot->chassis_temp_c,
            ['warning' => (float) $temperature['warning'], 'critical' => (float) $temperature['critical']],
            '°C'
        );
    }

    private function syncUpperBound(
        NetworkInventoryDevice $device,
        string $key,
        string $eventType,
        string $title,
        mixed $value,
        array $thresholds,
        string $unit
    ): void {
        if (! is_numeric($value)) {
            return;
        }

        $number = (float) $value;
        $warning = is_numeric($thresholds['warning'] ?? null) ? (float) $thresholds['warning'] : null;
        $critical = is_numeric($thresholds['critical'] ?? null) ? (float) $thresholds['critical'] : null;
        $severity = $critical !== null && $number >= $critical
            ? 'critical'
            : ($warning !== null && $number >= $warning ? 'warning' : null);

        $fingerprint = 'olt-live:'.$key.':'.$device->id;
        if ($severity === null) {
            $this->resolve($device, $fingerprint, ucfirst($key).' returned below its configured alert threshold.');
            return;
        }

        $this->raise(
            $device,
            $fingerprint,
            $eventType,
            $title,
            ucfirst($key).' is '.$number.$unit.'; configured warning is '.($warning ?? '—').$unit.' and critical is '.($critical ?? '—').$unit.'.',
            $severity,
            ['metric' => $key, 'value' => $number, 'unit' => $unit, 'warning_threshold' => $warning, 'critical_threshold' => $critical]
        );
    }

    private function syncLowerBound(
        NetworkInventoryDevice $device,
        string $key,
        string $eventType,
        string $title,
        mixed $value,
        array $thresholds,
        string $unit,
        array $metadata = []
    ): void {
        if (! is_numeric($value)) {
            return;
        }

        $number = (float) $value;
        $warning = is_numeric($thresholds['warning'] ?? null) ? (float) $thresholds['warning'] : null;
        $critical = is_numeric($thresholds['critical'] ?? null) ? (float) $thresholds['critical'] : null;
        $severity = $critical !== null && $number <= $critical
            ? 'critical'
            : ($warning !== null && $number <= $warning ? 'warning' : null);

        $fingerprint = 'olt-live:'.$key.':'.$device->id;
        if ($severity === null) {
            $this->resolve($device, $fingerprint, 'Measured optical RX has recovered above its configured alert threshold.');
            return;
        }

        $this->raise(
            $device,
            $fingerprint,
            $eventType,
            $title,
            'Measured average ONU RX is '.$number.$unit.'; warning threshold is '.($warning ?? '—').$unit.' and critical threshold is '.($critical ?? '—').$unit.'.',
            $severity,
            $metadata + ['metric' => 'onu_rx_dbm', 'value' => $number, 'unit' => $unit, 'warning_threshold' => $warning, 'critical_threshold' => $critical]
        );
    }

    private function raise(
        NetworkInventoryDevice $device,
        string $fingerprint,
        string $eventType,
        string $title,
        string $message,
        string $severity,
        array $metadata = []
    ): void {
        DB::transaction(function () use ($device, $fingerprint, $eventType, $title, $message, $severity, $metadata): void {
            $event = NetworkEvent::query()
                ->where('fingerprint', $fingerprint)
                ->whereIn('status', ['open', 'acknowledged'])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($event) {
                $event->update([
                    'severity' => $severity,
                    'message' => $message,
                    'occurrences' => max(1, (int) $event->occurrences) + 1,
                    'last_seen_at' => now(),
                    'metadata' => $metadata,
                ]);

                return;
            }

            NetworkEvent::create([
                'device_id' => $device->id,
                'source_type' => 'olt-live-telemetry',
                'event_type' => $eventType,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'status' => 'open',
                'fingerprint' => $fingerprint,
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => $metadata,
            ]);
        });
    }

    private function resolve(NetworkInventoryDevice $device, string $fingerprint, string $message): void
    {
        DB::transaction(function () use ($device, $fingerprint, $message): void {
            NetworkEvent::query()
                ->where('device_id', $device->id)
                ->where('fingerprint', $fingerprint)
                ->whereIn('status', ['open', 'acknowledged'])
                ->lockForUpdate()
                ->get()
                ->each(fn (NetworkEvent $event) => $event->update([
                    'status' => 'resolved',
                    'resolved_by' => null,
                    'resolved_at' => now(),
                    'last_seen_at' => now(),
                    'message' => $message,
                ]));
        });
    }
}
