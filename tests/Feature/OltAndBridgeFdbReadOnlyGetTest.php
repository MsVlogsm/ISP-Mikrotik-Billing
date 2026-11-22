<?php

namespace Tests\Feature;

use App\Http\Controllers\BridgeFdbController;
use App\Http\Controllers\OltDashboardController;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\User;
use App\Services\Olt\BridgeFdbSnmpService;
use App\Services\Olt\OltLiveMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OltAndBridgeFdbReadOnlyGetTest extends TestCase
{
    use RefreshDatabase;

    public function test_olt_dashboard_get_uses_saved_data_and_never_polls_live_metrics(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Read-only GET OLT',
            'ip_address' => '192.0.2.40',
            'status' => 'online',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create());

        $metrics = \Mockery::mock(OltLiveMetricsService::class);
        $metrics->shouldNotReceive('poll');
        $this->app->instance(OltLiveMetricsService::class, $metrics);

        $route = Route::getRoutes()->getByName('network-inventory.olt.dashboard');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());

        $response = app(OltDashboardController::class)->show(
            Request::create(route('network-inventory.olt.dashboard', $device), 'GET'),
            $device
        );

        $this->assertSame('xlink.olt-dashboard', $response->name());
        $this->assertStringContainsString('Read-only GET OLT', $response->render());
        $this->assertDatabaseCount('olt_live_metric_snapshots', 0);
    }

    public function test_bridge_fdb_get_uses_saved_snapshot_and_never_calls_the_polling_service(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Read-only GET Bridge FDB OLT',
            'ip_address' => '192.0.2.41',
            'status' => 'online',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create());

        $poller = \Mockery::mock(BridgeFdbSnmpService::class);
        $poller->shouldNotReceive('poll');
        $this->app->instance(BridgeFdbSnmpService::class, $poller);

        $route = Route::getRoutes()->getByName('network-inventory.device.bridge-fdb');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());

        $response = app(BridgeFdbController::class)->index(
            Request::create(route('network-inventory.device.bridge-fdb', $device), 'GET'),
            $device
        );

        $this->assertSame('xlink.bridge-fdb', $response->name());
        $this->assertStringContainsString('Read-only GET Bridge FDB OLT', $response->render());
        $this->assertSame(0, NetworkBridgeFdbSnapshot::query()
            ->where('network_inventory_device_id', $device->id)
            ->count());
    }
}
