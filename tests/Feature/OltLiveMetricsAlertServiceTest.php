<?php

namespace Tests\Feature;

use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\OltLiveMetricPort;
use App\Models\OltLiveMetricSnapshot;
use App\Services\Olt\OltLiveMetricsAlertService;
use App\Services\Olt\OltLiveMetricsService;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OltLiveMetricsAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private NetworkInventoryDevice $olt;

    private OltLiveMetricsAlertService $alerts;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->olt = NetworkInventoryDevice::query()->create([
            'type' => 'olt',
            'name' => 'P2 Test OLT',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'firmware' => 'V1.0.2R',
            'status' => 'online',
            'health_status' => 'online',
            'adapter_config' => json_encode([]),
        ]);

        $this->alerts = app(OltLiveMetricsAlertService::class);
    }

    public function test_high_cpu_and_memory_thresholds_create_deduplicated_alerts(): void
    {
        $snapshot = $this->snapshot(['cpu_percent' => 96, 'memory_percent' => 92]);

        $this->alerts->sync($this->olt, $snapshot);
        $this->alerts->sync($this->olt, $snapshot);

        $cpu = NetworkEvent::where('fingerprint', 'olt-live:cpu:'.$this->olt->id)->firstOrFail();
        $memory = NetworkEvent::where('fingerprint', 'olt-live:memory:'.$this->olt->id)->firstOrFail();

        $this->assertSame('critical', $cpu->severity);
        $this->assertSame(2, (int) $cpu->occurrences);
        $this->assertSame('warning', $memory->severity);
        $this->assertSame(2, (int) $memory->occurrences);
        $this->assertSame('olt-live-telemetry', $cpu->source_type);
    }

    public function test_recovered_metric_resolves_the_existing_alert(): void
    {
        $this->alerts->sync($this->olt, $this->snapshot(['cpu_percent' => 90]));
        $this->alerts->sync($this->olt, $this->snapshot(['cpu_percent' => 30]));

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:cpu:'.$this->olt->id,
            'status' => 'resolved',
        ]);
        $this->assertSame(1, NetworkEvent::where('fingerprint', 'olt-live:cpu:'.$this->olt->id)->count());
    }

    public function test_pon_down_and_weak_optical_rx_create_alerts_from_measured_port_values(): void
    {
        $snapshot = $this->snapshot();

        OltLiveMetricPort::query()->create([
            'snapshot_id' => $snapshot->id,
            'olt_device_id' => $this->olt->id,
            'if_index' => 101,
            'interface_name' => 'EPON0/1',
            'role' => 'pon',
            'oper_status' => 'down',
            'onu_total' => 12,
            'onu_online' => 8,
            'rx_sample_count' => 3,
            'avg_rx_dbm' => -28.5,
            'sampled_at' => now(),
        ]);

        $this->alerts->sync($this->olt, $snapshot->load('ports'));

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:pon-down:'.$this->olt->id.':101',
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:optical-rx:101:'.$this->olt->id,
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    public function test_missing_metrics_do_not_create_temperature_or_optical_alerts(): void
    {
        $snapshot = $this->snapshot([
            'cpu_percent' => null,
            'memory_percent' => null,
            'chassis_temp_c' => 80,
        ]);

        $this->alerts->sync($this->olt, $snapshot);

        $this->assertSame(0, NetworkEvent::count());
    }

    public function test_temperature_alert_requires_an_explicit_per_device_threshold(): void
    {
        $this->olt->update([
            'adapter_config' => json_encode([
                'alert_thresholds' => [
                    'temperature_c' => ['warning' => 60, 'critical' => 75],
                ],
            ]),
        ]);

        $this->alerts->sync($this->olt, $this->snapshot(['chassis_temp_c' => 80]));

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:temperature:'.$this->olt->id,
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    public function test_sfp_ddm_alerts_require_both_a_real_ddm_source_and_explicit_transceiver_thresholds(): void
    {
        $this->olt->update([
            'adapter_config' => json_encode([
                'alert_thresholds' => [
                    'sfp' => [
                        'temperature_c' => ['warning' => 70, 'critical' => 80],
                        'tx_power_dbm' => ['warning' => -8, 'critical' => -10],
                    ],
                ],
            ]),
        ]);

        $withoutSource = $this->snapshot();
        OltLiveMetricPort::query()->create([
            'snapshot_id' => $withoutSource->id,
            'olt_device_id' => $this->olt->id,
            'if_index' => 301,
            'interface_name' => 'SFP1/1',
            'role' => 'uplink_sfp',
            'oper_status' => 'up',
            'sfp_temperature_c' => 90,
            'sfp_tx_power_dbm' => -12,
            'sampled_at' => now(),
        ]);
        $this->alerts->sync($this->olt, $withoutSource->load('ports'));
        $this->assertSame(0, NetworkEvent::count(), 'A numeric field without an explicit DDM source is not trusted.');

        $withSource = $this->snapshot();
        OltLiveMetricPort::query()->create([
            'snapshot_id' => $withSource->id,
            'olt_device_id' => $this->olt->id,
            'if_index' => 302,
            'interface_name' => 'SFP1/2',
            'role' => 'uplink_sfp',
            'oper_status' => 'up',
            'sfp_temperature_c' => 85,
            'sfp_tx_power_dbm' => -11,
            'ddm_source' => 'explicit-device-sfp-ddm-oids',
            'sampled_at' => now(),
        ]);
        $this->alerts->sync($this->olt, $withSource->load('ports'));

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:sfp-temperature:302:'.$this->olt->id,
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:sfp-tx-power:302:'.$this->olt->id,
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    public function test_poll_failure_is_deduplicated_and_successful_poll_resolves_it_without_leaking_exception_text(): void
    {
        $exception = new RuntimeException('private-snmp-community-should-not-be-logged');

        $this->alerts->pollFailed($this->olt, $exception);
        $this->alerts->pollFailed($this->olt, $exception);

        $event = NetworkEvent::where('fingerprint', 'olt-live:poll-failed:'.$this->olt->id)->firstOrFail();

        $this->assertSame(2, (int) $event->occurrences);
        $this->assertStringNotContainsString('private-snmp-community', $event->message);
        $this->assertStringNotContainsString('private-snmp-community', json_encode($event->metadata));

        $this->alerts->sync($this->olt, $this->snapshot());

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:poll-failed:'.$this->olt->id,
            'status' => 'resolved',
        ]);
    }

    public function test_all_down_uplinks_raise_critical_and_recovery_resolves_the_incident(): void
    {
        $down = $this->snapshot();

        OltLiveMetricPort::query()->create([
            'snapshot_id' => $down->id,
            'olt_device_id' => $this->olt->id,
            'if_index' => 201,
            'interface_name' => 'GE0/1',
            'role' => 'uplink_ge',
            'oper_status' => 'down',
            'sampled_at' => now(),
        ]);

        $this->alerts->sync($this->olt, $down->load('ports'));

        $up = $this->snapshot();
        OltLiveMetricPort::query()->create([
            'snapshot_id' => $up->id,
            'olt_device_id' => $this->olt->id,
            'if_index' => 201,
            'interface_name' => 'GE0/1',
            'role' => 'uplink_ge',
            'oper_status' => 'up',
            'sampled_at' => now()->addMinute(),
        ]);

        $this->alerts->sync($this->olt, $up->load('ports'));

        $this->assertDatabaseHas('network_events', [
            'fingerprint' => 'olt-live:uplink-down:'.$this->olt->id.':201',
            'severity' => 'critical',
            'status' => 'resolved',
        ]);
    }

    public function test_live_metrics_service_records_a_safe_failure_incident_before_rethrowing(): void
    {
        $manager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $manager->shouldReceive('read')
            ->once()
            ->with($this->olt)
            ->andThrow(new RuntimeException('secret-snmp-community'));

        $service = new OltLiveMetricsService($manager, $this->alerts);

        try {
            $service->poll($this->olt);
            $this->fail('The original poll exception should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('secret-snmp-community', $exception->getMessage());
        }

        $event = NetworkEvent::where('fingerprint', 'olt-live:poll-failed:'.$this->olt->id)->firstOrFail();
        $this->assertSame('open', $event->status);
        $this->assertStringNotContainsString('secret-snmp-community', $event->message);
        $this->assertStringNotContainsString('secret-snmp-community', json_encode($event->metadata));
    }

    private function snapshot(array $overrides = []): OltLiveMetricSnapshot
    {
        return OltLiveMetricSnapshot::query()->create(array_merge([
            'olt_device_id' => $this->olt->id,
            'sampled_at' => now(),
            'interface_count' => 0,
            'pon_up' => 0,
            'pon_down' => 0,
            'uplink_up' => 0,
            'uplink_down' => 0,
            'onu_total' => 0,
            'onu_online' => 0,
            'onu_offline' => 0,
            'onu_unknown' => 0,
            'mapped_customers' => 0,
            'poll_duration_ms' => 5,
            'poll_status' => 'success',
        ], $overrides));
    }
}
