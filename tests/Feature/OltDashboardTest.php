<?php

namespace Tests\Feature;

use App\Http\Controllers\OltDashboardController;
use App\Models\NetworkInventoryDevice;
use App\Models\OltMonitoringSnapshot;
use App\Models\OltOnuCustomerMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OltDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_olt_dashboard_displays_saved_monitoring_and_signal_data_without_live_polling(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'OLT Dashboard Test',
            'vendor' => 'VSOL',
            'model' => 'V1600D4',
            'ip_address' => '192.0.2.10',
            'snmp_port' => 161,
            'snmp_version' => '2C',
            'status' => 'online',
            'health_status' => 'online',
            'pon_ports' => 8,
            'is_active' => true,
        ]);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '12',
            'pon_port' => '1',
            'onu_mac' => '00:11:22:33:44:55',
            'status' => 'online',
            'rx_power' => -18.4,
            'tx_power' => 1.8,
            'opm_source' => 'test-opm',
            'opm_last_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        OltMonitoringSnapshot::create([
            'olt_device_id' => $olt->id,
            'sampled_at' => now(),
            'onu_total' => 1,
            'onu_online' => 1,
            'onu_offline' => 0,
            'onu_unknown' => 0,
            'mapped_onus' => 1,
            'mapped_customers' => 1,
            'rx_sample_count' => 1,
            'signal_good' => 1,
            'signal_warning' => 0,
            'signal_critical' => 0,
            'signal_no_sample' => 0,
            'avg_rx_power' => -18.4,
            'min_rx_power' => -18.4,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user);

        $response = app(OltDashboardController::class)->show(request(), $olt);
        $this->assertSame('xlink.olt-dashboard', $response->name());

        $html = $response->render();
        $this->assertStringContainsString('OLT Dashboard Test', $html);
        $this->assertStringContainsString('ONU Signal Overview', $html);
        $this->assertStringContainsString('Discovered ONUs', $html);
        $this->assertStringContainsString('−20 dBm', $html);
        $this->assertStringContainsString('Sync ONU', $html);
    }

    public function test_read_only_olt_snapshot_defaults_and_signal_fields_are_persisted(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Snapshot Test OLT',
            'status' => 'online',
        ]);

        $snapshot = OltMonitoringSnapshot::create([
            'olt_device_id' => $olt->id,
            'sampled_at' => now(),
            'onu_total' => 4,
            'onu_online' => 3,
            'onu_offline' => 1,
            'onu_unknown' => 0,
            'mapped_onus' => 2,
            'mapped_customers' => 2,
            'rx_sample_count' => 2,
            'signal_good' => 1,
            'signal_warning' => 1,
            'signal_critical' => 0,
            'signal_no_sample' => 2,
            'avg_rx_power' => -23.1,
            'min_rx_power' => -25.2,
        ]);

        $this->assertSame($olt->id, $snapshot->olt_device_id);
        $this->assertSame(4, (int) $snapshot->onu_total);
        $this->assertSame(1, (int) $snapshot->onu_offline);
        $this->assertSame(2, (int) $snapshot->signal_no_sample);
        $this->assertEquals(-23.1, (float) $snapshot->avg_rx_power);
    }
}
