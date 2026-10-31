<?php

namespace App\Http\Controllers;

use App\Models\NetworkInventoryDevice;
use App\Models\NetworkInventoryHealthCheck;
use App\Models\OltMonitoringSnapshot;
use App\Models\OltOnuCustomerMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class OltDashboardController extends Controller
{
    public function show(Request $request, NetworkInventoryDevice $device): View
    {
        abort_unless($device->type === 'olt', 404);

        $latestSnapshot = OltMonitoringSnapshot::where('olt_device_id', $device->id)
            ->latest('sampled_at')
            ->first();

        $latestHealth = NetworkInventoryHealthCheck::where('network_inventory_device_id', $device->id)
            ->latest('checked_at')
            ->first();

        $mappingBase = OltOnuCustomerMapping::where('olt_device_id', $device->id);
        $mappingCounts = (clone $mappingBase)->selectRaw(
            "COUNT(*) as total,
             SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online,
             SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline,
             SUM(CASE WHEN status = 'unknown' OR status IS NULL THEN 1 ELSE 0 END) as unknown,
             SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) as mapped_onus,
             COUNT(DISTINCT customer_id) as mapped_customers"
        )->first();

        $stats = [
            'total' => $latestSnapshot?->onu_total ?? $device->onu_total ?? (int) ($mappingCounts->total ?? 0),
            'online' => $latestSnapshot?->onu_online ?? $device->onu_online ?? (int) ($mappingCounts->online ?? 0),
            'offline' => $latestSnapshot?->onu_offline ?? (int) ($mappingCounts->offline ?? 0),
            'unknown' => $latestSnapshot?->onu_unknown ?? (int) ($mappingCounts->unknown ?? 0),
            'mapped_onus' => $latestSnapshot?->mapped_onus ?? (int) ($mappingCounts->mapped_onus ?? 0),
            'mapped_customers' => $latestSnapshot?->mapped_customers ?? (int) ($mappingCounts->mapped_customers ?? 0),
            'rx_sample_count' => $latestSnapshot?->rx_sample_count ?? 0,
            'signal_good' => $latestSnapshot?->signal_good ?? 0,
            'signal_warning' => $latestSnapshot?->signal_warning ?? 0,
            'signal_critical' => $latestSnapshot?->signal_critical ?? 0,
            'signal_no_sample' => $latestSnapshot?->signal_no_sample ?? (int) ($mappingCounts->total ?? 0),
            'avg_rx_power' => $latestSnapshot?->avg_rx_power,
            'min_rx_power' => $latestSnapshot?->min_rx_power,
            'avg_latency_ms' => $latestSnapshot?->avg_latency_ms ?? $device->last_latency_ms,
        ];

        $signalTotal = (int) $stats['rx_sample_count'];
        $signalPercentages = [
            'good' => $signalTotal ? round(($stats['signal_good'] / $signalTotal) * 100, 1) : 0,
            'warning' => $signalTotal ? round(($stats['signal_warning'] / $signalTotal) * 100, 1) : 0,
            'critical' => $signalTotal ? round(($stats['signal_critical'] / $signalTotal) * 100, 1) : 0,
        ];

        $history = OltMonitoringSnapshot::where('olt_device_id', $device->id)
            ->where('sampled_at', '>=', now()->subDays(30))
            ->orderBy('sampled_at')
            ->get();

        $charts = [
            $this->buildChart(
                $history->filter(fn ($item) => $item->sampled_at->greaterThanOrEqualTo(now()->subDay())),
                'onu_online',
                'hour',
                'Hourly ONU Availability',
                'Online ONUs · Last 24 hours',
                'ONUs'
            ),
            $this->buildChart(
                $history->filter(fn ($item) => $item->sampled_at->greaterThanOrEqualTo(now()->subDays(7))),
                'onu_online',
                'day',
                'Weekly ONU Availability',
                'Online ONUs · Last 7 days',
                'ONUs'
            ),
            $this->buildChart(
                $history->filter(fn ($item) => $item->sampled_at->greaterThanOrEqualTo(now()->subDays(15)) && $item->avg_rx_power !== null),
                'avg_rx_power',
                'day',
                'Optical RX Trend',
                'Average RX · Last 15 days',
                'dBm'
            ),
            $this->buildChart(
                $history,
                'onu_offline',
                'day',
                'Monthly Offline Trend',
                'Offline ONUs · Last 30 days',
                'ONUs'
            ),
        ];

        $ponDistribution = (clone $mappingBase)
            ->whereNotNull('pon_port')
            ->selectRaw('pon_port, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as online', ['online'])
            ->groupBy('pon_port')
            ->orderBy('pon_port')
            ->get();

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $onusQuery = (clone $mappingBase)->with(['customer', 'pppUser'])
            ->orderByRaw("CASE status WHEN 'online' THEN 0 WHEN 'offline' THEN 1 ELSE 2 END")
            ->orderBy('pon_port')
            ->orderBy('onu_id');

        if ($search !== '') {
            $onusQuery->where(function ($query) use ($search) {
                $query->where('onu_id', 'like', "%{$search}%")
                    ->orWhere('onu_mac', 'like', "%{$search}%")
                    ->orWhere('onu_serial', 'like', "%{$search}%")
                    ->orWhere('pon_port', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('customer_unique_id', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('pppUser', fn ($pppQuery) => $pppQuery->where('username', 'like', "%{$search}%"));
            });
        }
        if (in_array($status, ['online', 'offline', 'unknown'], true)) {
            $onusQuery->where('status', $status);
        }

        $onus = $onusQuery->paginate(20)->withQueryString();

        $opmFreshMinutes = max(1, (int) config('olt_dashboard.opm_fresh_minutes', 30));
        $opmFreshCutoff = now()->subMinutes($opmFreshMinutes);
        $freshOpmCount = (clone $mappingBase)
            ->where('opm_last_seen_at', '>=', $opmFreshCutoff)
            ->whereNotNull('rx_power')
            ->count();

        $alerts = $this->buildAlerts($device, $stats, $latestSnapshot, $latestHealth, $freshOpmCount, $opmFreshMinutes);

        $rawCommunity = $device->getRawOriginal('snmp_community');
        $deviceInfo = [
            'name' => $device->name,
            'ip' => $device->ip_address ?: $device->host,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'firmware' => $device->firmware,
            'serial' => $device->serial_no,
            'pon_ports' => $device->pon_ports,
            'olt_type' => $device->olt_type_id,
            'snmp_port' => $device->snmp_port ?: 161,
            'snmp_version' => $device->snmp_version ?: '2C',
            'snmp_configured' => filled($rawCommunity),
            'last_checked_at' => $device->last_checked_at,
            'last_latency_ms' => $device->last_latency_ms,
            'location' => $device->location,
            'health_status' => $device->health_status ?: $device->status,
        ];

        return view('xlink.olt-dashboard', compact(
            'device',
            'stats',
            'latestSnapshot',
            'latestHealth',
            'signalTotal',
            'signalPercentages',
            'charts',
            'ponDistribution',
            'onus',
            'search',
            'status',
            'freshOpmCount',
            'opmFreshMinutes',
            'alerts',
            'deviceInfo'
        ));
    }

    public function sync(NetworkInventoryDevice $device): RedirectResponse
    {
        abort_unless($device->type === 'olt', 404);

        $exitCode = Artisan::call('olt:sync-onu-mappings', ['--device' => $device->id]);
        $output = trim(Artisan::output());

        return redirect()
            ->route('network-inventory.olt.dashboard', $device)
            ->with('olt_sync_success', $exitCode === 0)
            ->with('olt_sync_message', $output !== '' ? $output : 'ONU synchronization finished without additional output.');
    }

    private function buildChart(
        Collection $snapshots,
        string $field,
        string $bucket,
        string $title,
        string $subtitle,
        string $unit
    ): array {
        $grouped = $snapshots->groupBy(function ($snapshot) use ($bucket) {
            return $bucket === 'hour'
                ? $snapshot->sampled_at->format('Y-m-d H:00')
                : $snapshot->sampled_at->format('Y-m-d');
        });

        $values = $grouped->map(function ($items) use ($field) {
            return $items->whereNotNull($field)->avg($field);
        })->filter(fn ($value) => $value !== null)->values();

        $labels = $grouped->keys()->values();
        if ($values->isEmpty()) {
            return ['title' => $title, 'subtitle' => $subtitle, 'unit' => $unit, 'points' => '', 'latest' => null, 'empty' => true];
        }

        $numeric = $values->map(fn ($value) => (float) $value);
        $minimum = (float) $numeric->min();
        $maximum = (float) $numeric->max();
        $range = max(1.0, $maximum - $minimum);
        $count = $numeric->count();
        $coordinates = [];

        foreach ($numeric as $index => $value) {
            $x = $count === 1 ? 160 : 12 + ($index * (296 / ($count - 1)));
            $y = 88 - ((($value - $minimum) / $range) * 66);
            $coordinates[] = number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
        }

        $latest = (float) $numeric->last();

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'unit' => $unit,
            'points' => implode(' ', $coordinates),
            'latest' => $latest,
            'empty' => false,
            'labels' => $labels->all(),
        ];
    }

    private function buildAlerts(
        NetworkInventoryDevice $device,
        array $stats,
        ?OltMonitoringSnapshot $snapshot,
        ?NetworkInventoryHealthCheck $health,
        int $freshOpmCount,
        int $opmFreshMinutes
    ): array {
        $alerts = [];

        if ($device->status === 'offline' || ($health && in_array($health->status, ['down', 'offline'], true))) {
            $alerts[] = ['level' => 'critical', 'title' => 'OLT management connection is down', 'detail' => 'The latest SNMP health check did not confirm management reachability.'];
        } elseif ($device->status !== 'online') {
            $alerts[] = ['level' => 'warning', 'title' => 'OLT status is not confirmed online', 'detail' => 'Run a read-only ONU sync or SNMP health check to refresh the device status.'];
        }

        if ((int) $stats['offline'] > 0) {
            $alerts[] = ['level' => 'warning', 'title' => number_format($stats['onu_offline']).' ONU(s) are offline', 'detail' => 'Review offline ONU records and affected subscribers before making changes.'];
        }

        if ((int) $stats['signal_critical'] > 0) {
            $alerts[] = ['level' => 'critical', 'title' => number_format($stats['signal_critical']).' ONU(s) have critical RX power', 'detail' => 'RX is below '.config('olt_dashboard.signal_warning_min_dbm', -27).' dBm in the latest verified snapshot.'];
        }

        if ((int) $stats['signal_warning'] > 0) {
            $alerts[] = ['level' => 'warning', 'title' => number_format($stats['signal_warning']).' ONU(s) have weak RX power', 'detail' => 'Check connector loss, splitter path and optical budget.'];
        }

        if (!$snapshot) {
            $alerts[] = ['level' => 'info', 'title' => 'No verified OLT monitoring snapshot yet', 'detail' => 'Use Sync ONU to collect the first read-only inventory snapshot.'];
        } elseif ($snapshot->sampled_at->lt(now()->subMinutes(10))) {
            $alerts[] = ['level' => 'warning', 'title' => 'ONU telemetry is stale', 'detail' => 'The last complete read-only inventory snapshot is '.$snapshot->sampled_at->diffForHumans().'.'];
        }

        if ($freshOpmCount === 0) {
            $alerts[] = ['level' => 'info', 'title' => 'Fresh optical readings are unavailable', 'detail' => 'RX/TX signal analytics remain unavailable until the OLT returns timestamped OPM readings.'];
        } elseif ($freshOpmCount < (int) $stats['total']) {
            $alerts[] = ['level' => 'info', 'title' => 'Optical readings are partial', 'detail' => "{$freshOpmCount} of {$stats['total']} ONUs have RX readings within {$opmFreshMinutes} minutes."];
        }

        return $alerts;
    }
}
