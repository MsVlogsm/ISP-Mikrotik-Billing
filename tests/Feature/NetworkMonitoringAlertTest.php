<?php

namespace Tests\Feature;

use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkMonitoringAlertTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(array $overrides = []): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::query()->create(array_merge([
            'type' => 'switch',
            'name' => 'Test Switch',
            'ip_address' => '127.0.0.1',
            'health_port' => 9,
            'monitor_enabled' => true,
            'status' => 'online',
            'health_status' => 'ready',
        ], $overrides));
    }

    public function test_monitor_creates_a_single_critical_alert_for_unreachable_device(): void
    {
        $device = $this->createDevice();

        $this->artisan('app:check-network-inventory')->assertSuccessful();

        $this->assertDatabaseHas('network_events', [
            'device_id' => $device->id,
            'event_type' => 'device_down',
            'severity' => 'critical',
            'status' => 'open',
            'fingerprint' => 'device-down:'.$device->id,
            'occurrences' => 1,
        ]);

        $this->artisan('app:check-network-inventory')->assertSuccessful();

        $this->assertSame(1, NetworkEvent::where('device_id', $device->id)->count());
        $this->assertSame(2, (int) NetworkEvent::where('device_id', $device->id)->value('occurrences'));
    }

    public function test_recovered_device_resolves_existing_alert(): void
    {
        $device = $this->createDevice();
        NetworkEvent::create([
            'device_id' => $device->id,
            'source_type' => 'network-monitoring',
            'event_type' => 'device_down',
            'severity' => 'critical',
            'title' => 'Device unreachable: Test Switch',
            'message' => 'test',
            'status' => 'open',
            'fingerprint' => 'device-down:'.$device->id,
            'occurrences' => 3,
            'first_seen_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinute(),
        ]);

        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (! $listener) {
            $this->markTestSkipped('Unable to create a local test listener.');
        }
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        $device->update(['health_port' => $port, 'health_status' => 'down', 'status' => 'offline']);

        $this->artisan('app:check-network-inventory')->assertSuccessful();

        $this->assertDatabaseHas('network_events', [
            'device_id' => $device->id,
            'status' => 'resolved',
            'fingerprint' => 'device-down:'.$device->id,
        ]);

        fclose($listener);
    }
}
