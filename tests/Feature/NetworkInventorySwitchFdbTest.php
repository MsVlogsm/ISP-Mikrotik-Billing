<?php

namespace Tests\Feature;

use App\Models\NetworkInventoryDevice;
use App\Models\RouterList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkInventorySwitchFdbTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_inventory_stores_encrypted_snmp_credentials_and_explicit_proxy_selection(): void
    {
        $proxy = RouterList::create([
            'router_name' => 'FDB Proxy Router',
            'ip_address' => '192.0.2.1',
            'username' => 'api-reader',
            'password' => 'router-secret',
            'api_port' => 8728,
            'action' => 'connected',
        ]);

        $this->withoutMiddleware();

        $response = $this->post(route('network-inventory.devices.store', ['type' => 'switch']), [
            'name' => 'Access Switch 01',
            'ip_address' => '192.0.2.50',
            'vendor' => 'Generic',
            'status' => 'unknown',
            'snmp_version' => '2C',
            'snmp_port' => 161,
            'snmp_community' => 'switch-read-only',
            'bridge_fdb_proxy_router_id' => $proxy->id,
        ]);

        $response->assertRedirect();

        $device = NetworkInventoryDevice::query()->where('name', 'Access Switch 01')->sole();
        $this->assertSame('2C', $device->snmp_version);
        $this->assertSame(161, (int) $device->snmp_port);
        $this->assertSame('switch-read-only', $device->snmp_community);
        $this->assertNotSame('switch-read-only', $device->getRawOriginal('snmp_community'));
        $this->assertSame(
            ['bridge_fdb' => ['proxy_router_id' => $proxy->id]],
            json_decode((string) $device->adapter_config, true)
        );
    }

    public function test_switch_inventory_accepts_the_explicit_ssh_read_only_fallback_without_snmp_community(): void
    {
        $this->withoutMiddleware();

        $response = $this->post(route('network-inventory.devices.store', ['type' => 'switch']), [
            'name' => 'SSH Managed Switch',
            'ip_address' => '192.0.2.51',
            'vendor' => 'BDCOM',
            'status' => 'unknown',
            'ssh_enabled' => '1',
            'username' => 'readonly',
            'password' => 'ssh-secret',
            'ssh_port' => 22,
        ]);

        $response->assertRedirect();

        $device = NetworkInventoryDevice::query()->where('name', 'SSH Managed Switch')->sole();
        $this->assertTrue($device->ssh_enabled);
        $this->assertSame('readonly', $device->username);
        $this->assertNotSame('readonly', $device->getRawOriginal('username'));
        $this->assertSame('ssh-secret', $device->password);
        $this->assertSame(22, (int) $device->ssh_port);
    }

    public function test_switch_inventory_requires_snmp_or_explicit_ssh_read_only_access(): void
    {
        $this->withoutMiddleware();

        $response = $this->post(route('network-inventory.devices.store', ['type' => 'switch']), [
            'name' => 'Unconfigured Switch',
            'ip_address' => '192.0.2.52',
            'status' => 'unknown',
        ]);

        $response->assertSessionHasErrors('snmp_community');
        $this->assertDatabaseMissing('network_inventory_devices', ['name' => 'Unconfigured Switch']);
    }
}
