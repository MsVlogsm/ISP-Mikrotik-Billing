<?php

namespace Tests\Feature;

use App\Models\MikrotikBridgeHostEntry;
use App\Models\MikrotikBridgeHostMovement;
use App\Models\MikrotikBridgeHostSnapshot;
use App\Models\RouterList;
use App\Models\User;
use App\Services\MikrotikBridgeHostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MikrotikBridgeHostPollingTest extends TestCase
{
    use RefreshDatabase;

    private function router(): RouterList
    {
        return RouterList::create([
            'router_name' => 'L2-test-router',
            'ip_address' => '192.0.2.10',
            'username' => 'readonly',
            'password' => 'test-password',
            'api_port' => 8729,
            'api_ssl' => true,
            'action' => 'connected',
        ]);
    }

    private function service(array $responses): MikrotikBridgeHostService
    {
        return new class($responses) extends MikrotikBridgeHostService
        {
            public function __construct(private array $responses) {}

            protected function fetchHosts(RouterList $router): array
            {
                $response = array_shift($this->responses) ?? [];
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        };
    }

    public function test_it_stores_read_only_bridge_host_snapshot_with_mac_vlan_port_and_flags(): void
    {
        $router = $this->router();
        $service = $this->service([[
            [
                '.id' => '*1',
                'mac-address' => 'AA:BB:CC:DD:EE:01',
                'vid' => '100',
                'on-interface' => 'ether2',
                'bridge' => 'bridge1',
                'dynamic' => 'true',
                'local' => 'false',
                'external' => 'false',
                'disabled' => 'false',
                'invalid' => 'false',
            ],
            [
                '.id' => '*2',
                'mac-address' => 'AA:BB:CC:DD:EE:02',
                'vid' => '200',
                'on-interface' => 'bridge1',
                'bridge' => 'bridge1',
                'dynamic' => 'true',
                'local' => 'true',
                'external' => 'true',
                'disabled' => 'false',
                'invalid' => 'false',
            ],
        ]]);

        $snapshot = $service->poll($router);

        $this->assertSame('ok', $snapshot->status);
        $this->assertSame('routeros-api', $snapshot->source);
        $this->assertSame(2, $snapshot->entry_count);
        $this->assertSame(2, $snapshot->dynamic_count);
        $this->assertSame(1, $snapshot->local_count);
        $this->assertSame(1, $snapshot->external_count);
        $this->assertSame(true, $snapshot->meta['read_only']);
        $this->assertSame(false, $snapshot->meta['writes_performed']);

        $entry = $snapshot->entries->firstWhere('mac_address', 'aa:bb:cc:dd:ee:01');
        $this->assertNotNull($entry);
        $this->assertSame(100, $entry->vlan_id);
        $this->assertSame('bridge1', $entry->bridge_name);
        $this->assertSame('ether2', $entry->interface_name);
        $this->assertTrue($entry->is_dynamic);
        $this->assertFalse($entry->is_local);
        $this->assertDatabaseHas('mikrotik_bridge_host_entries', [
            'snapshot_id' => $snapshot->id,
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'vlan_id' => 100,
            'interface_name' => 'ether2',
        ]);
    }

    public function test_it_records_dynamic_non_local_mac_port_movement_between_snapshots(): void
    {
        $router = $this->router();
        $mac = 'AA:BB:CC:DD:EE:10';
        $service = $this->service([
            [[
                '.id' => '*10',
                'mac-address' => $mac,
                'vid' => '300',
                'on-interface' => 'ether1',
                'bridge' => 'bridge1',
                'dynamic' => 'true',
                'local' => 'false',
            ]],
            [[
                '.id' => '*11',
                'mac-address' => $mac,
                'vid' => '300',
                'on-interface' => 'ether3',
                'bridge' => 'bridge1',
                'dynamic' => 'true',
                'local' => 'false',
            ]],
        ]);

        Carbon::setTestNow('2026-11-28 01:00:00');
        $first = $service->poll($router);
        Carbon::setTestNow('2026-11-28 01:05:00');
        $second = $service->poll($router);
        Carbon::setTestNow();

        $this->assertSame('ok', $first->status);
        $this->assertSame('ok', $second->status);

        $movement = MikrotikBridgeHostMovement::query()->sole();
        $this->assertSame('aa:bb:cc:dd:ee:10', $movement->mac_address);
        $this->assertSame(300, $movement->vlan_id);
        $this->assertSame('bridge1', $movement->from_bridge);
        $this->assertSame('bridge1', $movement->to_bridge);
        $this->assertSame('ether1', $movement->from_interface);
        $this->assertSame('ether3', $movement->to_interface);
        $this->assertSame($first->id, $movement->previous_snapshot_id);
        $this->assertSame($second->id, $movement->snapshot_id);
        $this->assertSame(1, $movement->moves_in_10m);
        $this->assertFalse($movement->is_flapping);
    }

    public function test_it_does_not_record_movements_for_local_static_invalid_or_disabled_hosts(): void
    {
        $router = $this->router();
        $service = $this->service([
            [
                ['mac-address' => '00:00:00:00:00:01', 'vid' => '10', 'on-interface' => 'ether1', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'true'],
                ['mac-address' => '00:00:00:00:00:02', 'vid' => '10', 'on-interface' => 'ether1', 'bridge' => 'bridge1', 'dynamic' => 'false', 'local' => 'false'],
                ['mac-address' => '00:00:00:00:00:03', 'vid' => '10', 'on-interface' => 'ether1', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'false', 'invalid' => 'true'],
                ['mac-address' => '00:00:00:00:00:04', 'vid' => '10', 'on-interface' => 'ether1', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'false', 'disabled' => 'true'],
            ],
            [
                ['mac-address' => '00:00:00:00:00:01', 'vid' => '10', 'on-interface' => 'ether2', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'true'],
                ['mac-address' => '00:00:00:00:00:02', 'vid' => '10', 'on-interface' => 'ether2', 'bridge' => 'bridge1', 'dynamic' => 'false', 'local' => 'false'],
                ['mac-address' => '00:00:00:00:00:03', 'vid' => '10', 'on-interface' => 'ether2', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'false', 'invalid' => 'true'],
                ['mac-address' => '00:00:00:00:00:04', 'vid' => '10', 'on-interface' => 'ether2', 'bridge' => 'bridge1', 'dynamic' => 'true', 'local' => 'false', 'disabled' => 'true'],
            ],
        ]);

        Carbon::setTestNow('2026-11-28 02:00:00');
        $service->poll($router);
        Carbon::setTestNow('2026-11-28 02:05:00');
        $service->poll($router);
        Carbon::setTestNow();

        $this->assertSame(0, MikrotikBridgeHostMovement::query()->count());
    }

    public function test_api_failure_is_recorded_without_marking_a_successful_snapshot(): void
    {
        $router = $this->router();
        $service = $this->service([
            new RuntimeException('RouterOS API connection failed.'),
        ]);

        $snapshot = $service->poll($router);

        $this->assertSame('error', $snapshot->status);
        $this->assertSame(0, $snapshot->entry_count);
        $this->assertSame(false, $snapshot->meta['writes_performed']);
        $this->assertSame('RouterOS API connection failed.', $snapshot->error_message);
        $this->assertDatabaseHas('mikrotik_bridge_host_snapshots', [
            'id' => $snapshot->id,
            'router_list_id' => $router->id,
            'status' => 'error',
        ]);
    }

    public function test_read_only_page_displays_saved_mac_ports_and_movement_history(): void
    {
        $router = $this->router();
        $snapshot = MikrotikBridgeHostSnapshot::create([
            'router_list_id' => $router->id,
            'sampled_at' => now(),
            'source' => 'routeros-api',
            'status' => 'ok',
            'entry_count' => 1,
            'dynamic_count' => 1,
            'meta' => ['read_only' => true, 'writes_performed' => false],
        ]);

        MikrotikBridgeHostEntry::create([
            'snapshot_id' => $snapshot->id,
            'mac_address' => 'aa:bb:cc:dd:ee:20',
            'vlan_id' => 400,
            'bridge_name' => 'bridge1',
            'interface_name' => 'ether5',
            'is_dynamic' => true,
        ]);

        MikrotikBridgeHostMovement::create([
            'router_list_id' => $router->id,
            'snapshot_id' => $snapshot->id,
            'mac_address' => 'aa:bb:cc:dd:ee:20',
            'vlan_id' => 400,
            'from_bridge' => 'bridge1',
            'to_bridge' => 'bridge1',
            'from_interface' => 'ether2',
            'to_interface' => 'ether5',
            'moved_at' => now(),
            'moves_in_10m' => 1,
        ]);

        $role = Role::findOrCreate('Super Admin');
        $user = User::create([
            'name' => 'Bridge Host Test',
            'email' => uniqid('bridge_host_', true).'@test.local',
            'mobile' => uniqid('01'),
            'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->withoutMiddleware()
            ->get(route('mikrotik-bridge-hosts', ['router_id' => $router->id]))
            ->assertOk()
            ->assertSee('AA:BB:CC:DD:EE:20')
            ->assertSee('ether5')
            ->assertSee('ether2')
            ->assertSee('MAC Movement History');
    }
}
