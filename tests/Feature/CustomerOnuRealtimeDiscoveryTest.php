<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\Olt\CustomerOnuRealtimeSyncService;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\Olt\VsolCliProvisioningService;
use App\Services\Olt\VsolSshOpmCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CustomerOnuRealtimeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_offline_vsol_mapping_moves_to_unique_live_pppoe_onu_with_fresh_opm(): void
    {
        $scenario = $this->scenario([
            [
                'pon_port' => '2',
                'onu_id' => '11',
                'onu_mac' => '80:f7:a6:f9:9f:01',
                'status' => 'offline',
                'client_macs' => [],
            ],
            [
                'pon_port' => '2',
                'onu_id' => '8',
                'onu_mac' => 'a0:08:6f:4d:27:66',
                'status' => 'online',
                'client_macs' => ['00:eb:d8:7c:da:27'],
                'rx_power' => -23.10,
                'tx_power' => 1.86,
                'opm_source' => 'vsol-cli-ssh',
            ],
        ]);

        $result = $scenario['service']->sync($scenario['customer']);

        $this->assertSame('2', $result['pon']);
        $this->assertSame('8', $result['onu_id']);
        $this->assertTrue($result['opm_fresh']);
        $this->assertEquals(-23.10, $result['rx_power']);
        $this->assertEquals(1.86, $result['tx_power']);

        $scenario['previousMapping']->refresh();
        $scenario['targetMapping']->refresh();
        $scenario['link']->refresh();

        $this->assertNull($scenario['previousMapping']->customer_id);
        $this->assertNull($scenario['previousMapping']->ppp_user_id);
        $this->assertSame('offline', $scenario['previousMapping']->status);

        $this->assertSame($scenario['customer']->id, $scenario['targetMapping']->customer_id);
        $this->assertSame($scenario['ppp']->id, $scenario['targetMapping']->ppp_user_id);
        $this->assertSame('a0:08:6f:4d:27:66', $scenario['targetMapping']->onu_mac);
        $this->assertEquals(-23.10, (float) $scenario['targetMapping']->rx_power);
        $this->assertEquals(1.86, (float) $scenario['targetMapping']->tx_power);
        $this->assertSame($scenario['targetMapping']->id, $scenario['link']->olt_onu_customer_mapping_id);
    }

    public function test_ambiguous_live_pppoe_mac_on_multiple_vsol_onus_does_not_change_mapping(): void
    {
        $scenario = $this->scenario([
            [
                'pon_port' => '2',
                'onu_id' => '8',
                'onu_mac' => 'a0:08:6f:4d:27:66',
                'status' => 'online',
                'client_macs' => ['00:eb:d8:7c:da:27'],
                'rx_power' => -23.10,
                'tx_power' => 1.86,
                'opm_source' => 'vsol-cli-ssh',
            ],
            [
                'pon_port' => '2',
                'onu_id' => '9',
                'onu_mac' => 'a0:08:6f:4d:27:77',
                'status' => 'online',
                'client_macs' => ['00:eb:d8:7c:da:27'],
                'rx_power' => -22.40,
                'tx_power' => 1.80,
                'opm_source' => 'vsol-cli-ssh',
            ],
        ]);

        try {
            $scenario['service']->sync($scenario['customer']);
            $this->fail('An ambiguous live PPPoE MAC must not be mapped automatically.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('একই PPPoE client MAC একাধিক ONU interface-এ পাওয়া গেছে', $exception->getMessage());
        }

        $scenario['previousMapping']->refresh();
        $scenario['targetMapping']->refresh();
        $scenario['link']->refresh();

        $this->assertSame($scenario['customer']->id, $scenario['previousMapping']->customer_id);
        $this->assertSame('offline', $scenario['targetMapping']->status);
        $this->assertNull($scenario['targetMapping']->customer_id);
        $this->assertSame($scenario['previousMapping']->id, $scenario['link']->olt_onu_customer_mapping_id);
    }

    private function scenario(array $discoveredOnus): array
    {
        $routerName = 'BT0051-Reconciliation-Router';
        $username = 'bt.rubel2';
        $callerMac = '00:eb:d8:7c:da:27';

        RouterList::create([
            'router_name' => $routerName,
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-password',
            'action' => 'connected',
        ]);

        $ppp = PPPSecrets::create([
            'router_name' => $routerName,
            'username' => $username,
            'password' => 'test-secret',
            'service' => 'pppoe',
            'profile' => 'Home-20M',
            'status' => 'active',
            'caller_id' => '',
            'last_caller_id' => $callerMac,
        ]);

        $customer = CustomersInfo::create([
            'customer_unique_id' => 'BT0051-TEST',
            'customer_name' => 'ONU Reconciliation Test',
            'status' => 'active',
            'ppp_user_id' => $ppp->id,
        ]);

        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'BT-EPON-OLT Test',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);

        $previousMapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'pon_port' => '2',
            'onu_id' => '11',
            'onu_mac' => '80:f7:a6:f9:9f:01',
            'status' => 'offline',
        ]);

        $targetMapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'pon_port' => '2',
            'onu_id' => '8',
            'onu_mac' => 'a2:4f:a2:72:fe:c0',
            'status' => 'offline',
        ]);

        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $previousMapping->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'client_mac' => $callerMac,
            'source' => 'customer_list_realtime_sync:live_pppoe_session',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $adapterManager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapterManager->shouldReceive('read')
            ->once()
            ->with(\Mockery::on(fn ($device) => $device instanceof NetworkInventoryDevice && $device->id === $olt->id))
            ->andReturn([
                'ok' => true,
                'status' => 'ok',
                'onus' => [[
                    'pon_port' => '2',
                    'onu_id' => '11',
                    'onu_mac' => '80:f7:a6:f9:9f:01',
                    'status' => 'offline',
                ], [
                    'pon_port' => '2',
                    'onu_id' => '8',
                    'onu_mac' => 'a2:4f:a2:72:fe:c0',
                    'status' => 'offline',
                ]],
            ]);

        $opmCollector = \Mockery::mock(VsolSshOpmCollector::class);
        $opmCollector->shouldReceive('collect')
            ->once()
            ->andReturn([
                'ok' => true,
                'status' => 'ok',
                'onus' => $discoveredOnus,
                'meta' => ['read_only' => true, 'writes_performed' => false],
            ]);

        $mikrotik = \Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('mtQuote')->once()->with($username)->andReturn($username);
        $mikrotik->shouldReceive('singleRead')
            ->twice()
            ->andReturnUsing(function (...$arguments) use ($username, $callerMac): array {
                return $arguments[1] === '/ppp/active/print'
                    ? [['name' => $username, 'caller-id' => $callerMac]]
                    : [['name' => $username]];
            });

        $service = new CustomerOnuRealtimeSyncService(
            $adapterManager,
            \Mockery::mock(VsolCliProvisioningService::class),
            $opmCollector,
            $mikrotik,
        );

        return compact('service', 'customer', 'ppp', 'previousMapping', 'targetMapping', 'link');
    }
}
