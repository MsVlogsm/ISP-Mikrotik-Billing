<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\UniversalNetworkSearchController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UniversalNetworkSearchDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_unformatted_mac_finds_pppoe_caller_id_onu_mac_and_onu_subscriber_link(): void
    {
        RouterList::create(['router_name' => 'test-router', 'ip_address' => '127.0.0.1', 'username' => 'test-user', 'password' => 'test-password', 'action' => 'disconnected']);
        $ppp = PPPSecrets::create([
            'router_name' => 'test-router',
            'username' => 'universal-search-user',
            'service' => 'pppoe',
            'caller_id' => 'AA:BB:CC:DD:EE:01',
            'status' => 'active',
        ]);

        $customer = CustomersInfo::create([
            'customer_unique_id' => 'US-MAC-001',
            'customer_name' => 'Universal MAC Test',
            'ppp_user_id' => $ppp->id,
            'status' => 'active',
        ]);

        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Universal Search OLT',
            'ip_address' => '192.0.2.101',
            'status' => 'online',
            'is_active' => true,
        ]);

        $mapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'onu_id' => 'ONU-001',
            'onu_serial' => 'SERIAL-001',
            'onu_mac' => 'AA:BB:CC:DD:EE:01',
            'pon_port' => '1/1',
            'status' => 'online',
        ]);

        OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $mapping->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'client_mac' => 'AA:BB:CC:DD:EE:01',
            'source' => 'manual_verified',
            'status' => 'active',
        ]);

        $view = app(UniversalNetworkSearchController::class)->index(
            Request::create('/network-inventory/universal-search', 'GET', [
                'q' => 'aabbccddee01',
                'type' => 'mac',
            ])
        );
        $data = $view->getData();

        $this->assertCount(1, $data['pppUsers']);
        $this->assertCount(1, $data['onuMappings']);
        $this->assertCount(1, $data['onuLinks']);
        $this->assertSame('US-MAC-001', $data['onuLinks']->first()->customer->customer_unique_id);
    }

    public function test_ip_search_checks_both_ppp_remote_ip_and_legacy_ip_address(): void
    {
        RouterList::create(['router_name' => 'test-router', 'ip_address' => '127.0.0.1', 'username' => 'test-user', 'password' => 'test-password', 'action' => 'disconnected']);
        PPPSecrets::create([
            'router_name' => 'test-router',
            'username' => 'remote-ip-user',
            'service' => 'pppoe',
            'ppp_remote_ip' => '10.20.30.40',
            'status' => 'active',
        ]);

        PPPSecrets::create([
            'router_name' => 'test-router',
            'username' => 'legacy-ip-user',
            'service' => 'pppoe',
            'ip_address' => '10.20.30.41',
            'status' => 'active',
        ]);

        $controller = app(UniversalNetworkSearchController::class);

        $remoteIp = $controller->index(Request::create('/search', 'GET', [
            'q' => '10.20.30.40',
            'type' => 'ip',
        ]))->getData();

        $legacyIp = $controller->index(Request::create('/search', 'GET', [
            'q' => '10.20.30.41',
            'type' => 'ip',
        ]))->getData();

        $this->assertSame('remote-ip-user', $remoteIp['pppUsers']->sole()->username);
        $this->assertSame('legacy-ip-user', $legacyIp['pppUsers']->sole()->username);
    }

    public function test_mac_and_ip_search_include_live_pppoe_sessions_and_arp_entries(): void
    {
        RouterList::create([
            'router_name' => 'live-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-password',
            'api_port' => 8728,
            'action' => 'connected',
        ]);

        $mikrotik = \Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('singleRead')
            ->once()
            ->with('live-router', '/ppp/active/print', '/ppp active print without-paging terse', [], false, true)
            ->andReturn([
                ['name' => 'live-user', 'address' => '10.20.30.40', 'caller-id' => 'AA:BB:CC:DD:EE:01'],
            ]);
        $mikrotik->shouldReceive('singleRead')
            ->once()
            ->with('live-router', '/ip/arp/print', '/ip arp print without-paging terse', [], false, true)
            ->andReturn([
                ['address' => '10.20.30.40', 'mac-address' => 'AA:BB:CC:DD:EE:01', 'interface' => 'ether1', 'status' => 'reachable'],
            ]);
        $this->app->instance(MikrotikController::class, $mikrotik);

        $view = app(UniversalNetworkSearchController::class)->index(
            Request::create('/network-inventory/universal-search', 'GET', [
                'q' => 'aabbccddee01',
                'type' => 'mac',
            ])
        );
        $data = $view->getData();

        $this->assertCount(1, $data['liveSessions']);
        $this->assertSame('live-user', $data['liveSessions']->first()['username']);
        $this->assertSame('AA:BB:CC:DD:EE:01', $data['liveSessions']->first()['mac']);
        $this->assertCount(1, $data['arpEntries']);
        $this->assertSame('10.20.30.40', $data['arpEntries']->first()['ip']);
    }

    public function test_customer_search_keeps_default_soft_delete_scope(): void
    {
        $active = CustomersInfo::create([
            'customer_unique_id' => 'US-ACTIVE-001',
            'customer_name' => 'Active Search Customer',
            'status' => 'active',
        ]);

        $deleted = CustomersInfo::create([
            'customer_unique_id' => 'US-DELETED-001',
            'customer_name' => 'Deleted Search Customer',
            'status' => 'inactive',
        ]);
        $deleted->delete();

        $controller = app(UniversalNetworkSearchController::class);

        $activeResults = $controller->index(Request::create('/search', 'GET', [
            'q' => 'US-ACTIVE-001',
            'type' => 'name',
        ]))->getData();

        $deletedResults = $controller->index(Request::create('/search', 'GET', [
            'q' => 'US-DELETED-001',
            'type' => 'name',
        ]))->getData();

        $this->assertCount(1, $activeResults['customers']);
        $this->assertSame($active->id, $activeResults['customers']->sole()->id);
        $this->assertCount(0, $deletedResults['customers']);
    }
}
