<?php

namespace App\Services\Olt;

use App\Models\NetworkInventoryDevice;
use App\Models\OltLiveMetricPort;
use App\Models\OltLiveMetricSnapshot;
use App\Models\OltOnuCustomerMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OltLiveMetricsService
{
    public function __construct(private readonly OltReadOnlyAdapterManager $manager) {}

    public function poll(NetworkInventoryDevice $device): OltLiveMetricSnapshot
    {
        abort_unless($device->type === 'olt', 404);
        $started = microtime(true);
        $result = $this->manager->read($device);
        if (empty($result['ok']) || empty($result['meta']['read_only']) || ($result['meta']['writes_performed'] ?? true) !== false) {
            throw new RuntimeException('Live read-only telemetry failed: '.($result['message'] ?? 'No valid SNMP response.'));
        }

        $now = now();
        $deviceData = (array) ($result['device'] ?? []);
        $onus = array_values(array_filter((array) ($result['onus'] ?? []), 'is_array'));
        $interfaces = array_values(array_filter((array) ($result['interfaces'] ?? []), static fn ($row) => is_array($row)
            && in_array($row['role'] ?? 'other', ['pon','uplink_ge','uplink_sfp'], true)));

        // ONU rows are keyed by their explicit PON and ONU identity, never by walk order.
        $onuByPon = [];
        foreach ($onus as $onu) {
            $pon = $this->normalizePon($onu['pon_port'] ?? null);
            if ($pon === null) continue;
            $onuByPon[$pon][] = $onu;
        }

        $ponRows = array_values(array_filter($interfaces, static fn ($i) => ($i['role'] ?? null) === 'pon'));
        $uplinkRows = array_values(array_filter($interfaces, static fn ($i) => str_starts_with((string) ($i['role'] ?? ''), 'uplink_')));
        $ponUp = count(array_filter($ponRows, static fn ($i) => ($i['oper_status'] ?? 'unknown') === 'up'));
        $ponDown = count(array_filter($ponRows, static fn ($i) => ($i['oper_status'] ?? 'unknown') === 'down'));
        $uplinkUp = count(array_filter($uplinkRows, static fn ($i) => ($i['oper_status'] ?? 'unknown') === 'up'));
        $uplinkDown = count(array_filter($uplinkRows, static fn ($i) => ($i['oper_status'] ?? 'unknown') === 'down'));

        $online = count(array_filter($onus, static fn ($onu) => strtolower((string) ($onu['status'] ?? 'unknown')) === 'online'));
        $offline = count(array_filter($onus, static fn ($onu) => strtolower((string) ($onu['status'] ?? 'unknown')) === 'offline'));
        $unknown = max(0, count($onus) - $online - $offline);
        // Count only customer records whose stored ONU MAC is present in this
        // exact live poll. Stale/offline identities absent from the SNMP table
        // must not inflate the current connected-customer capacity figure.
        $liveMacs = array_values(array_unique(array_filter(array_map(
            static fn ($onu) => strtolower(trim((string) ($onu['onu_mac'] ?? ''))),
            $onus
        ), static fn ($mac) => preg_match('/^[0-9a-f]{2}(?::[0-9a-f]{2}){5}$/', $mac) === 1)));
        $mappedCustomers = $liveMacs
            ? (int) OltOnuCustomerMapping::where('olt_device_id', $device->id)
                ->whereNotNull('customer_id')
                ->whereIn(DB::raw('LOWER(onu_mac)'), $liveMacs)
                ->distinct('customer_id')->count('customer_id')
            : 0;

        $uptime = $this->parseUptime($deviceData['sysUpTime'] ?? null);
        $capacity = $this->capacityTotal($device, $deviceData['sysDescr'] ?? null, count($ponRows));
        $cpu = $this->boundedMetric($deviceData['cpu_percent'] ?? null, 0, 100);
        $memory = $this->boundedMetric($deviceData['memory_percent'] ?? null, 0, 100);
        $temperature = $this->boundedMetric($deviceData['chassis_temp_c'] ?? null, -20, 150);
        $hardwareAvailability = (array) data_get($result, 'meta.hardware_metric_availability', []);
        $pollDuration = max(0, (int) round((microtime(true) - $started) * 1000));

        return DB::transaction(function () use ($device, $result, $deviceData, $onus, $interfaces, $onuByPon, $ponUp, $ponDown, $uplinkUp, $uplinkDown, $online, $offline, $unknown, $mappedCustomers, $uptime, $capacity, $cpu, $memory, $temperature, $hardwareAvailability, $pollDuration, $now) {
            $snapshot = OltLiveMetricSnapshot::create([
                'olt_device_id' => $device->id,
                'sampled_at' => $now,
                'sys_descr' => $deviceData['sysDescr'] ?? null,
                'uptime_ticks' => $uptime['ticks'],
                'uptime_seconds' => $uptime['seconds'],
                'cpu_percent' => $cpu,
                'memory_percent' => $memory,
                'chassis_temp_c' => $temperature,
                'interface_count' => count($interfaces),
                'pon_up' => $ponUp,
                'pon_down' => $ponDown,
                'uplink_up' => $uplinkUp,
                'uplink_down' => $uplinkDown,
                'onu_total' => count($onus),
                'onu_online' => $online,
                'onu_offline' => $offline,
                'onu_unknown' => $unknown,
                'mapped_customers' => $mappedCustomers,
                'capacity_total' => $capacity,
                'poll_duration_ms' => $pollDuration,
                'poll_status' => 'success',
                'metrics' => [
                    'uptime_human' => $uptime['human'],
                    'hardware_metric_availability' => $hardwareAvailability,
                    'hardware_metric_note' => $this->hardwareNote($hardwareAvailability),
                    'interfaces_supported' => count($interfaces) > 0,
                    'read_only' => true,
                    'writes_performed' => false,
                    'mib_profile' => data_get($result, 'meta.mib_profile') ?? data_get($result, 'meta.profile'),
                    'profile_source' => data_get($result, 'meta.mib_profile_source') ?? data_get($result, 'meta.oid_source'),
                    'sys_descr' => $deviceData['sysDescr'] ?? null,
                    'capacity_per_pon' => $capacity !== null && $device->pon_ports ? (int) floor($capacity / max(1, (int) $device->pon_ports)) : null,
                    'capacity_basis' => $capacity !== null ? 'configured/model profile' : 'not configured for this model',
                    'adapter_message' => $result['message'] ?? null,
                ],
            ]);

            foreach ($interfaces as $interface) {
                $ifIndex = (int) ($interface['if_index'] ?? 0);
                if ($ifIndex <= 0) continue;
                $previous = OltLiveMetricPort::where('olt_device_id', $device->id)
                    ->where('if_index', $ifIndex)->where('sampled_at', '<', $now)->latest('sampled_at')->first();
                $inOctets = $this->counter($interface['in_octets'] ?? null);
                $outOctets = $this->counter($interface['out_octets'] ?? null);
                $elapsed = $previous ? max(0.001, $previous->sampled_at->diffInMilliseconds($now) / 1000) : null;
                $inBps = $elapsed !== null && $inOctets !== null && $previous?->in_octets !== null
                    ? $this->counterDelta($inOctets, (int) $previous->in_octets, $elapsed) : null;
                $outBps = $elapsed !== null && $outOctets !== null && $previous?->out_octets !== null
                    ? $this->counterDelta($outOctets, (int) $previous->out_octets, $elapsed) : null;

                $counterFields = ['in_errors','out_errors','in_discards','out_discards','in_packets','out_packets'];
                $counterValues = [];
                foreach ($counterFields as $counterField) $counterValues[$counterField] = $this->counter($interface[$counterField] ?? null);
                $packetHealth = $this->packetHealth($counterValues, $previous, $elapsed);

                $ponNumber = $this->ponFromInterface($interface['description'] ?? '');
                $portOnus = $ponNumber !== null ? ($onuByPon[$ponNumber] ?? []) : [];
                $rx = $this->numericValues($portOnus, 'rx_power');
                $tx = $this->numericValues($portOnus, 'tx_power');
                $distance = $this->numericValues($portOnus, 'distance_m');

                OltLiveMetricPort::create([
                    'snapshot_id' => $snapshot->id,
                    'olt_device_id' => $device->id,
                    'if_index' => $ifIndex,
                    'interface_name' => (string) ($interface['description'] ?? 'ifIndex '.$ifIndex),
                    'role' => (string) ($interface['role'] ?? 'other'),
                    'oper_status' => (string) ($interface['oper_status'] ?? 'unknown'),
                    'in_octets' => $inOctets,
                    'out_octets' => $outOctets,
                    'in_errors' => $counterValues['in_errors'],
                    'out_errors' => $counterValues['out_errors'],
                    'in_discards' => $counterValues['in_discards'],
                    'out_discards' => $counterValues['out_discards'],
                    'in_packets' => $counterValues['in_packets'],
                    'out_packets' => $counterValues['out_packets'],
                    'in_bps' => $inBps,
                    'out_bps' => $outBps,
                    'packet_health_percent' => $packetHealth,
                    'onu_total' => count($portOnus),
                    'onu_online' => count(array_filter($portOnus, static fn ($onu) => strtolower((string) ($onu['status'] ?? 'unknown')) === 'online')),
                    'rx_sample_count' => count($rx),
                    'avg_rx_dbm' => $rx ? round(array_sum($rx) / count($rx), 2) : null,
                    'avg_tx_dbm' => $tx ? round(array_sum($tx) / count($tx), 2) : null,
                    'avg_distance_m' => $distance ? round(array_sum($distance) / count($distance), 2) : null,
                    'sampled_at' => $now,
                ]);
            }

            // A successful authenticated read-only SNMP response confirms management reachability.
            $device->forceFill([
                'status' => 'online',
                'health_status' => 'online',
                'last_checked_at' => $now,
                'onu_total' => count($onus),
                'onu_online' => $online,
                'customer_count' => $mappedCustomers,
            ])->save();

            return $snapshot->load('ports');
        });
    }

    private function parseUptime(mixed $value): array
    {
        $text = trim((string) ($value ?? ''));
        $ticks = null;
        if (preg_match('/\((\d+)\)/', $text, $match)) $ticks = (int) $match[1];
        elseif (is_numeric($value)) $ticks = (int) $value;
        $seconds = $ticks !== null ? (int) floor($ticks / 100) : null;
        if ($seconds === null && preg_match('/(\d+) days?,\s*(\d+):(\d+):(\d+)/i', $text, $m)) {
            $seconds = ((int)$m[1] * 86400) + ((int)$m[2] * 3600) + ((int)$m[3] * 60) + (int)$m[4];
        }
        if ($seconds === null) return ['ticks'=>null,'seconds'=>null,'human'=>null];
        $days = intdiv($seconds, 86400); $hours = intdiv($seconds % 86400, 3600); $minutes = intdiv($seconds % 3600, 60); $secs = $seconds % 60;
        return ['ticks'=>$ticks,'seconds'=>$seconds,'human'=>($days ? $days.'d ' : '').sprintf('%02dh %02dm %02ds',$hours,$minutes,$secs)];
    }

    private function capacityTotal(NetworkInventoryDevice $device, mixed $sysDescr, int $ponCount): ?int
    {
        $config = $device->adapter_config;
        if (is_string($config)) $config = json_decode($config, true) ?: [];
        $config = is_array($config) ? $config : [];
        $perPon = (int) ($config['capacity_per_pon'] ?? 0);
        if ($perPon <= 0) {
            $model = strtoupper((string) $device->model.' '.(string) $sysDescr);
            if (str_contains($model, 'V1601E04-DP') || str_contains($model, 'V1600D4')) $perPon = 64;
        }
        $ports = (int) ($device->pon_ports ?: $ponCount);
        return $perPon > 0 && $ports > 0 ? $perPon * $ports : null;
    }

    private function hardwareNote(array $availability): string
    {
        $missing = array_keys(array_filter($availability, static fn ($available) => !$available));
        if (!$missing) return 'All configured hardware metrics are available.';
        return 'Not exposed by the current SNMP profile: '.implode(', ', $missing).'.';
    }

    private function normalizePon(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/(?:EPON0\/|PON)?(\d+)$/i', $value, $m)) return $m[1];
        return $value !== '' ? $value : null;
    }

    private function ponFromInterface(string $description): ?string
    {
        return preg_match('/^EPON\d+\/(\d+)$/i', trim($description), $m) ? $m[1] : null;
    }

    private function numericValues(array $rows, string $key): array
    {
        return array_values(array_map('floatval', array_filter(array_column($rows, $key), 'is_numeric')));
    }

    private function boundedMetric(mixed $value, float $min, float $max): ?float
    {
        if (!is_numeric($value)) return null;
        $number = (float) $value;
        return $number >= $min && $number <= $max ? $number : null;
    }

    private function counter(mixed $value): ?int
    {
        if (!is_numeric($value)) return null;
        $number = (float) $value;
        if ($number < 0 || $number > PHP_INT_MAX) return null;
        return (int) $number;
    }

    private function counterDelta(int $current, int $previous, float $elapsed): ?float
    {
        $delta = $current - $previous;
        // Handle a Counter32 wrap only when the stored counter is within its 32-bit range.
        // A reset of a Counter64 is treated as a fresh baseline, not fabricated traffic.
        if ($delta < 0 && $previous <= 4294967295 && $previous > 3000000000 && $current < 1000000000) {
            $delta = (4294967296 - $previous) + $current;
        }
        if ($delta < 0 || $elapsed <= 0) return null;
        return round(($delta * 8) / $elapsed, 2);
    }

    private function packetHealth(array $current, ?OltLiveMetricPort $previous, ?float $elapsed): ?float
    {
        if (!$previous || $elapsed === null) return null;
        $bad = 0; $packets = 0;
        foreach (['in_errors','out_errors','in_discards','out_discards'] as $field) {
            $now = $current[$field] ?? null; $before = $previous->{$field};
            if ($now === null || $before === null) return null;
            $delta = $this->counterDelta($now, (int)$before, $elapsed);
            if ($delta === null) return null;
            $bad += $delta;
        }
        foreach (['in_packets','out_packets'] as $field) {
            $now = $current[$field] ?? null; $before = $previous->{$field};
            if ($now === null || $before === null) return null;
            $delta = $this->counterDelta($now, (int)$before, $elapsed);
            if ($delta === null) return null;
            $packets += $delta;
        }
        if ($packets <= 0) return null;
        return round(max(0, 100 - min(100, ($bad / $packets) * 100)), 4);
    }
}
