<?php

namespace Tests\Feature;

use App\Http\Controllers\BridgeFdbController;
use App\Models\NetworkBridgeFdbEntry;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\User;
use App\Services\Olt\BridgeFdbSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BridgeFdbControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_olt_bridge_fdb_page_shows_saved_snapshot_without_triggering_live_polling(): void
    {
        config(['bridge_fdb.live_poll_enabled' => false]);

        $device = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Bridge FDB OLT',
            'vendor' => 'VSOL',
            'model' => 'V1600D4',
            'ip_address' => '192.0.2.50',
            'snmp_port' => 161,
            'snmp_version' => '2C',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);

        $snapshot = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $device->id,
            'sampled_at' => now(),
            'source' => 'dot1q',
            'status' => 'ok',
            'entry_count' => 1,
            'learned_count' => 1,
            'other_count' => 0,
            'self_count' => 0,
            'invalid_count' => 0,
            'poll_duration_ms' => 125,
            'meta' => ['read_only' => true, 'writes_performed' => false],
        ]);

        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $snapshot->id,
            'mac_address' => '00:11:22:33:44:55',
            'vlan_id' => 100,
            'fdb_id' => 100,
            'bridge_port' => 12,
            'if_index' => 120,
            'interface_name' => 'EPON0/1:12',
            'entry_status' => 'learned',
            'status_code' => 3,
            'source' => 'dot1q',
        ]);

        $this->actingAs(User::factory()->create());

        $response = app(BridgeFdbController::class)->index(Request::create('/'), $device);
        $html = $response->render();

        $this->assertSame('xlink.bridge-fdb', $response->name());
        $this->assertStringContainsString('L2 Bridge / FDB', $html);
        $this->assertStringContainsString('00:11:22:33:44:55', $html);
        $this->assertStringContainsString('VLAN-aware Q-BRIDGE-MIB', $html);
        $this->assertStringContainsString('Live Poll Disabled', $html);
    }

    public function test_failed_poll_keeps_last_successful_fdb_data_visible_with_warning(): void
    {
        config(['bridge_fdb.live_poll_enabled' => false]);

        $device = NetworkInventoryDevice::create([
            'type' => 'switch',
            'name' => 'Access Switch',
            'vendor' => 'Generic',
            'ip_address' => '192.0.2.61',
            'status' => 'online',
            'is_active' => true,
        ]);

        $successful = NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $device->id,
            'sampled_at' => now()->subMinutes(15),
            'source' => 'dot1q',
            'status' => 'ok',
            'entry_count' => 1,
            'learned_count' => 1,
            'other_count' => 0,
            'self_count' => 0,
            'invalid_count' => 0,
            'poll_duration_ms' => 125,
            'meta' => ['read_only' => true, 'writes_performed' => false],
        ]);

        NetworkBridgeFdbEntry::create([
            'snapshot_id' => $successful->id,
            'mac_address' => '00:11:22:33:44:66',
            'vlan_id' => 100,
            'fdb_id' => 100,
            'bridge_port' => 12,
            'if_index' => 120,
            'interface_name' => 'EPON0/1:12',
            'entry_status' => 'learned',
            'status_code' => 3,
            'source' => 'dot1q',
        ]);

        NetworkBridgeFdbSnapshot::create([
            'network_inventory_device_id' => $device->id,
            'sampled_at' => now(),
            'source' => 'dot1q',
            'status' => 'error',
            'entry_count' => 0,
            'learned_count' => 0,
            'other_count' => 0,
            'self_count' => 0,
            'invalid_count' => 0,
            'poll_duration_ms' => 750,
            'error_message' => 'FDB status table is not readable.',
            'meta' => ['read_only' => true, 'writes_performed' => false],
        ]);

        $this->actingAs(User::factory()->create());

        $response = app(BridgeFdbController::class)->index(Request::create('/'), $device);
        $html = $response->render();

        $this->assertSame($successful->id, $response->getData()['snapshot']->id);
        $this->assertStringContainsString('POLL FAILED', $html);
        $this->assertStringContainsString('FDB status table is not readable.', $html);
        $this->assertStringContainsString('00:11:22:33:44:66', $html);
        $this->assertStringContainsString('Last successful poll:', $html);
    }

    public function test_bridge_fdb_poll_is_blocked_until_explicitly_enabled(): void
    {
        config(['bridge_fdb.live_poll_enabled' => false]);

        $device = NetworkInventoryDevice::create([
            'type' => 'switch',
            'name' => 'Access Switch',
            'vendor' => 'Generic',
            'ip_address' => '192.0.2.60',
            'status' => 'unknown',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create());

        $response = app(BridgeFdbController::class)->poll(Request::create('/poll', 'POST'), $device, app(BridgeFdbSnmpService::class));

        $this->assertSame(route('network-inventory.device.bridge-fdb', $device), $response->getTargetUrl());
        $this->assertFalse(NetworkBridgeFdbSnapshot::where('network_inventory_device_id', $device->id)->exists());
    }

    public function test_bridge_fdb_routes_are_registered_for_olt_and_switch_inventory_devices(): void
    {
        $this->assertTrue(Route::has('network-inventory.device.bridge-fdb'));
        $this->assertTrue(Route::has('network-inventory.device.bridge-fdb.poll'));
    }

    public function test_it_extracts_exact_epon_and_gpon_onu_slots_from_fdb_interfaces(): void
    {
        $controller = app(BridgeFdbController::class);
        $method = new ReflectionMethod($controller, 'onuSlotFromInterface');
        $method->setAccessible(true);

        $this->assertSame(['pon_port' => '1', 'onu_id' => '12'], $method->invoke($controller, 'EPON0/1:12'));
        $this->assertSame(['pon_port' => '2', 'onu_id' => '34'], $method->invoke($controller, 'GPON0/2:34'));
        $this->assertSame(['pon_port' => '1', 'onu_id' => '1'], $method->invoke($controller, 'gpon-onu_1/1/1:1'));
        $this->assertNull($method->invoke($controller, 'GE0/1'));

        $normalizePon = new ReflectionMethod($controller, 'normalizePon');
        $normalizePon->setAccessible(true);
        $this->assertSame('2', $normalizePon->invoke($controller, 'GPON0/2'));
    }

    public function test_non_olt_or_switch_inventory_records_are_rejected(): void
    {
        $device = new NetworkInventoryDevice(['type' => 'router', 'name' => 'Router']);
        $this->expectException(HttpException::class);

        app(BridgeFdbController::class)->index(Request::create('/'), $device);
    }
}
