<?php

namespace Tests\Feature;

use App\Livewire\NetworkTopology;
use App\Livewire\NetworkMap;
use App\Models\NetworkInventoryDevice;
use App\Models\MainSiteData;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyMap;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NetworkTopologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_designer_saves_and_publishes_a_connection_between_inventory_nodes(): void
    {
        $router = RouterList::create([
            'router_name' => 'Core Router', 'ip_address' => '192.0.2.1',
            'username' => 'test', 'password' => 'test', 'action' => 'connected',
        ]);
        $switch = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'POP Switch', 'ip_address' => '192.0.2.2',
            'status' => 'unknown', 'health_status' => 'unknown',
        ]);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'device:'.$switch->id)
            ->set('connection_type', 'uplink')
            ->set('label', 'Core uplink')
            ->call('saveLink')
            ->assertSee('Core uplink');

        $link = NetworkTopologyLink::firstOrFail();
        $this->assertFalse($link->is_published);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('publish', $link->id);
        $this->assertDatabaseHas('network_topology_links', ['id' => $link->id, 'is_published' => 1, 'status' => 'active']);
    }

    public function test_published_live_topology_nodes_can_be_used_in_connections(): void
    {
        $router = RouterList::create([
            'router_name' => 'Access Router', 'ip_address' => '192.0.2.3',
            'username' => 'test', 'password' => 'test', 'action' => 'connected',
        ]);
        $mapId = (int) NetworkTopologyMap::where('is_default', true)->value('id');
        $node = NetworkTopologyNode::create([
            'map_id' => $mapId, 'type' => 'splitter', 'name' => 'SPL-POP-01', 'is_published' => false,
        ]);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('publishNode', $node->id);
        $node->refresh();
        $this->assertTrue($node->is_published);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'topology:'.$node->id)
            ->set('connection_type', 'fiber_core')
            ->call('saveLink');

        $link = NetworkTopologyLink::firstOrFail();
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('publish', $link->id);
        $this->assertTrue($link->fresh()->is_published);
    }


    public function test_outside_plant_assets_accept_validated_coordinates_and_appear_on_network_map(): void
    {
        $mapId = (int) NetworkTopologyMap::where('is_default', true)->value('id');

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('newNodeType', 'cabinet')
            ->set('newNodeName', 'CAB-MIRPUR-TEST')
            ->set('newNodeLocation', 'Mirpur')
            ->set('newNodeLatitude', '23.810300')
            ->set('newNodeLongitude', '90.412500')
            ->call('saveNode')
            ->assertHasNoErrors();

        $node = NetworkTopologyNode::where('name', 'CAB-MIRPUR-TEST')->firstOrFail();
        $this->assertSame('cabinet', $node->type);
        $this->assertEqualsWithDelta(23.8103, (float) $node->latitude, 0.000001);
        $this->assertEqualsWithDelta(90.4125, (float) $node->longitude, 0.000001);
        $this->assertSame($mapId, (int) $node->map_id);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('publishNode', $node->id);
        $this->assertTrue($node->fresh()->is_published);

        $mapComponent = app(NetworkMap::class);
        $mapComponent->mount();
        $mapView = $mapComponent->render();
        $mappedNodes = collect($mapView->getData()['nodes']);
        $mappedAsset = $mappedNodes->firstWhere('map_key', 'topology:'.$node->id);
        $this->assertNotNull($mappedAsset);
        $this->assertSame('cabinet', $mappedAsset['kind']);
        $this->assertSame('unknown', $mappedAsset['status']);
        $this->assertEqualsWithDelta(23.8103, $mappedAsset['lat'], 0.000001);
        $this->assertEqualsWithDelta(90.4125, $mappedAsset['lng'], 0.000001);
    }

    public function test_topology_and_optical_audit_routes_render_for_network_admin(): void
    {
        $this->get(route('network-topology.designer'))->assertOk()->assertSee('Topology Designer');
        $this->get(route('network-topology.live'))->assertOk()->assertSee('Live Topology');
        $this->get(route('optical-audit'))->assertOk()->assertSee('Virtual OTDR / Optical Audit');
    }

    public function test_network_map_uses_saved_admin_default_map_settings(): void
    {
        MainSiteData::setValue('ipilot_gis_default_latitude', '24.9000');
        MainSiteData::setValue('ipilot_gis_default_longitude', '91.8000');
        MainSiteData::setValue('ipilot_gis_default_zoom', 14);
        MainSiteData::setValue('ipilot_gis_default_basemap', 'satellite');

        $component = app(NetworkMap::class);
        $component->mount();
        $view = $component->render();
        $mapConfig = $view->getData()['mapConfig'];

        $this->assertSame([91.8, 24.9], $mapConfig['center']);
        $this->assertSame(14, $mapConfig['zoom']);
        $this->assertSame('satellite', $mapConfig['basemap']);
    }
}
