<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OnuMacLedger;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use App\Services\OnuMacLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OnuMacLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The suite uses no production credentials; provide a deterministic ephemeral key
        // because RouterList encrypts credentials while test fixtures are being created.
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_saved_mapping_is_recorded_and_ledger_can_restore_only_the_same_active_customer_ppp_pair(): void
    {
        $olt = $this->olt();
        $ppp = $this->ppp('ledger-router-a', 'ledger-user-a');
        $customer = $this->customer('LEDGER-CID-A', $ppp->id);
        $mapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'onu_id' => 'ONU-A',
            'onu_serial' => 'SERIAL-A',
            'onu_mac' => 'AA:BB:CC:DD:EE:01',
            'pon_port' => '1',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $service = app(OnuMacLedgerService::class);
        $record = $service->recordMapping($mapping);

        $this->assertNotNull($record);
        $this->assertSame($customer->id, $record->customer_id);
        $this->assertSame('LEDGER-CID-A', $record->customer_unique_id);
        $this->assertSame('ledger-user-a', $record->pppoe_username);

        $mapping->delete();
        $candidate = $service->findExactCustomerMatch($olt->id, 'aa-bb-cc-dd-ee-01', 'serial-a');
        $this->assertNotNull($candidate);
        $this->assertSame($customer->id, $candidate->id);
    }

    public function test_mac_change_is_logged_only_when_serial_is_the_same(): void
    {
        $olt = $this->olt();
        $ppp = $this->ppp('ledger-router-b', 'ledger-user-b');
        $customer = $this->customer('LEDGER-CID-B', $ppp->id);
        $mapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'onu_id' => 'ONU-B',
            'onu_serial' => 'SERIAL-B',
            'onu_mac' => 'AA:BB:CC:DD:EE:02',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $service = app(OnuMacLedgerService::class);
        $first = $service->recordMapping($mapping);
        $mapping->onu_mac = 'AA:BB:CC:DD:EE:22';
        $mapping->save();
        $updated = $service->recordMapping($mapping->fresh());

        $this->assertSame($first->id, $updated->id);
        $this->assertSame('AA:BB:CC:DD:EE:02', $updated->previous_mac);
        $this->assertSame('AA:BB:CC:DD:EE:22', $updated->current_mac);
        $this->assertNotNull($updated->mac_changed_at);
        $this->assertSame($customer->id, $updated->customer_id);
    }

    public function test_ledger_refuses_identity_with_a_conflicting_serial(): void
    {
        $olt = $this->olt();
        $ppp = $this->ppp('ledger-router-c', 'ledger-user-c');
        $customer = $this->customer('LEDGER-CID-C', $ppp->id);
        OnuMacLedger::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'customer_unique_id' => $customer->customer_unique_id,
            'current_mac' => 'AA:BB:CC:DD:EE:03',
            'serial_no' => 'SERIAL-C',
            'pppoe_username' => $ppp->username,
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $candidate = app(OnuMacLedgerService::class)
            ->findExactCustomerMatch($olt->id, 'AA:BB:CC:DD:EE:03', 'SERIAL-OTHER');

        $this->assertNull($candidate);
    }

    public function test_ambiguous_mac_ledger_records_never_auto_map(): void
    {
        $olt = $this->olt();
        foreach (['SERIAL-D1', 'SERIAL-D2'] as $serial) {
            OnuMacLedger::create([
                'olt_device_id' => $olt->id,
                'current_mac' => 'AA:BB:CC:DD:EE:04',
                'serial_no' => $serial,
                'status' => 'online',
            ]);
        }

        $candidate = app(OnuMacLedgerService::class)
            ->findExactCustomerMatch($olt->id, 'AA:BB:CC:DD:EE:04', null);

        $this->assertNull($candidate);
    }

    public function test_super_admin_can_open_mac_ledger_dashboard(): void
    {
        $this->get(route('network-inventory.onu-mac-ledger'))
            ->assertOk()
            ->assertSee('ONU MAC Ledger')
            ->assertSee('Stable ONU identity');
    }

    private function olt(): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'MAC Ledger Test OLT',
            'vendor' => 'VSOL',
            'model' => 'V1601',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);
    }

    private function ppp(string $routerName, string $username): PPPSecrets
    {
        RouterList::create([
            'router_name' => $routerName,
            'ip_address' => '127.0.0.1',
            'username' => 'ledger-test-router-user',
            'password' => 'ledger-test-router-password',
            'action' => 'disconnected',
        ]);

        return PPPSecrets::create([
            'router_name' => $routerName,
            'username' => $username,
            'password' => 'ledger-test-ppp-secret',
            'service' => 'pppoe',
            'profile' => 'Home-20M',
            'status' => 'active',
        ]);
    }

    private function customer(string $id, int $pppUserId): CustomersInfo
    {
        return CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Customer '.$id,
            'status' => 'active',
            'ppp_user_id' => $pppUserId,
        ]);
    }
}
