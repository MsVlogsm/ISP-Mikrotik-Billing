<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\AiEngineerDiagnosticService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AiEngineerDiagnosticServiceTest extends TestCase
{
    private MikrotikController $mikrotik;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->createDiagnosticSchema();
        $this->mikrotik = Mockery::mock(MikrotikController::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_active_ppp_secret_with_matching_live_session_is_reported_online(): void
    {
        [$customer] = $this->makeCustomerWithRouter();
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([
            ['name' => 'customer-ppp', 'address' => '10.0.0.10', 'uptime' => '1h2m'],
        ]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertTrue($result['ok']);
        $this->assertSame('online', $result['service_path']['pppoe']['live_session']['state']);
        $this->assertSame('info', $result['severity']);
        $this->assertTrue($result['read_only']);
    }

    public function test_active_ppp_secret_without_live_session_is_a_warning(): void
    {
        [$customer] = $this->makeCustomerWithRouter();
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('offline', $result['service_path']['pppoe']['live_session']['state']);
        $this->assertSame('warning', $result['severity']);
        $this->assertStringContainsString('no live pppoe session', strtolower(implode(' ', $result['likely_causes'])));
    }

    public function test_router_health_older_than_fifteen_minutes_is_stale(): void
    {
        [$customer, $router] = $this->makeCustomerWithRouter();
        $router->forceFill(['last_checked_at' => now()->subMinutes(20), 'last_latency_ms' => 12])->save();
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([
            ['name' => 'customer-ppp'],
        ]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('stale', $result['service_path']['router']['check_freshness']);
        $this->assertGreaterThan(900, $result['service_path']['router']['check_age_seconds']);
    }

    public function test_customer_search_matches_ppp_username_and_returns_selectable_customer(): void
    {
        [$customer] = $this->makeCustomerWithRouter();

        $results = (new AiEngineerDiagnosticService($this->mikrotik))->searchCustomers('customer-ppp');

        $this->assertCount(1, $results);
        $this->assertSame($customer->id, $results[0]['id']);
        $this->assertSame('TEST-001', $results[0]['customer_unique_id']);
        $this->assertSame('customer-ppp', $results[0]['ppp_username']);
    }

    public function test_customer_search_matches_additional_customer_details(): void
    {
        [$customer] = $this->makeCustomerWithRouter();
        $customer->forceFill([
            'contact_person' => 'Contact Person X',
            'parents_name' => 'Parent Name Y',
            'spouse_name' => 'Spouse Name Z',
            'address' => 'House 22, Example Road',
            'email' => 'customer@example.test',
            'mobile' => '01700000000',
            'alternative_mobile' => '01811112222',
            'identification_no' => 'ID-ABC-77',
            'profession' => 'Teacher',
        ])->save();

        foreach (['Contact Person X', 'Parent Name Y', 'Spouse Name Z', 'Example Road', 'customer@example.test', '01811112222', 'ID-ABC-77', 'Teacher'] as $term) {
            $results = (new AiEngineerDiagnosticService($this->mikrotik))->searchCustomers($term);
            $this->assertSame($customer->id, $results[0]['id'] ?? null, 'Search failed for: '.$term);
        }
    }

    public function test_customer_search_returns_empty_for_blank_query(): void
    {
        $this->makeCustomerWithRouter();

        $this->assertSame([], (new AiEngineerDiagnosticService($this->mikrotik))->searchCustomers('   '));
    }

    public function test_customer_without_onu_mapping_is_reported_not_mapped(): void
    {
        [$customer] = $this->makeCustomerWithRouter();
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([['name' => 'customer-ppp']]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('not_mapped', $result['service_path']['onu']['state']);
        $this->assertStringContainsString('No discovered OLT/ONU mapping', implode(' ', $result['evidence']));
    }

    public function test_linked_offline_olt_is_included_as_a_likely_cause(): void
    {
        [$customer, , $ppp] = $this->makeCustomerWithRouter();
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Test OLT', 'ip_address' => '192.0.2.10',
            'status' => 'offline', 'health_status' => 'offline',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'onu_id' => '1', 'status' => 'online',
        ]);
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([['name' => 'customer-ppp']]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('warning', $result['severity']);
        $this->assertSame('offline', $result['service_path']['onu']['olt_state']);
        $this->assertStringContainsString('not confirmed', strtolower(implode(' ', $result['likely_causes'])));
        $this->assertTrue($result['read_only']);
    }

    public function test_stale_offline_router_status_is_not_treated_as_a_confirmed_current_outage(): void
    {
        [$customer, $router] = $this->makeCustomerWithRouter();
        $router->forceFill(['action' => 'offline', 'last_checked_at' => now()->subMinutes(30)])->save();

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('warning', $result['severity']);
        $this->assertSame('low', $result['diagnostic_confidence']);
        $this->assertStringContainsString('not confirmed', strtolower(implode(' ', $result['likely_causes'])));
        $this->assertTrue($result['read_only']);
    }

    public function test_stale_onu_los_snapshot_is_reported_as_unconfirmed(): void
    {
        [$customer, , $ppp] = $this->makeCustomerWithRouter();
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Fresh OLT', 'ip_address' => '192.0.2.11',
            'status' => 'online', 'health_status' => 'online', 'last_checked_at' => now(),
        ]);
        $mapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'onu_id' => '9', 'status' => 'los',
        ]);
        DB::table('olt_onu_customer_mappings')->where('id', $mapping->id)->update([
            'updated_at' => now()->subMinutes(30),
        ]);
        $this->mikrotik->shouldReceive('singleRead')->once()->andReturn([
            ['name' => 'customer-ppp', 'address' => '10.0.0.10'],
        ]);

        $result = (new AiEngineerDiagnosticService($this->mikrotik))->diagnoseCustomer($customer->customer_unique_id);

        $this->assertSame('warning', $result['severity']);
        $this->assertSame('stale', $result['service_path']['onu']['mapping_check_freshness']);
        $this->assertStringContainsString('not confirmed', strtolower(implode(' ', $result['likely_causes'])));
        $this->assertNotSame('high', $result['diagnostic_confidence']);
    }

    private function makeCustomerWithRouter(): array
    {
        $router = RouterList::create([
            'router_name' => 'Test Router', 'ip_address' => '192.0.2.1',
            'username' => 'test-user', 'password' => 'test-password', 'action' => 'connected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name, 'username' => 'customer-ppp',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'TEST-001', 'customer_name' => 'Test Customer',
            'status' => 'active', 'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $router, $ppp];
    }

    private function createDiagnosticSchema(): void
    {
        Schema::create('router_lists', function (Blueprint $table) {
            $table->id(); $table->string('router_name')->unique(); $table->string('ip_address');
            $table->string('username'); $table->string('password'); $table->integer('ssh_port')->nullable();
            $table->integer('api_port')->nullable(); $table->string('action')->default('connected');
            $table->unsignedInteger('last_latency_ms')->nullable(); $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('p_p_p_secrets', function (Blueprint $table) {
            $table->id(); $table->string('router_name')->nullable(); $table->string('username')->nullable();
            $table->string('password')->default('-'); $table->rememberToken(); $table->string('service')->default('-');
            $table->string('profile')->default('-'); $table->string('caller_id')->nullable(); $table->string('comment')->nullable();
            $table->string('ppp_remote_ip')->nullable(); $table->string('bandwidth')->nullable(); $table->timestamp('uptime')->nullable();
            $table->timestamp('downtime')->nullable(); $table->timestamp('last_logged_out')->nullable();
            $table->string('last_caller_id')->nullable(); $table->string('last_disconnect_reason')->nullable();
            $table->string('routes')->nullable(); $table->string('ipv6_routes')->nullable(); $table->string('status')->default('pending');
            $table->string('package_name')->nullable(); $table->timestamps();
        });
        Schema::create('customers_infos', function (Blueprint $table) {
            $table->id(); $table->string('customer_unique_id')->unique(); $table->string('customer_name')->nullable();
            $table->string('contact_person')->nullable(); $table->string('parents_name')->nullable(); $table->string('spouse_name')->nullable();
            $table->string('address')->nullable(); $table->string('mobile')->nullable(); $table->string('alternative_mobile')->nullable();
            $table->string('identification_no')->nullable(); $table->string('profession')->nullable();
            $table->string('email')->nullable(); $table->string('status')->default('pending');
            $table->unsignedBigInteger('ppp_user_id')->nullable(); $table->string('package_name')->nullable();
            $table->softDeletes(); $table->timestamps();
        });
        Schema::create('package_lists', function (Blueprint $table) {
            $table->id(); $table->string('package')->nullable(); $table->timestamps();
        });
        Schema::create('billing_infos', function (Blueprint $table) {
            $table->id(); $table->string('customer_bill_unique_id')->nullable();
            $table->decimal('total_due_amount', 10, 2)->nullable(); $table->decimal('due_amount', 10, 2)->nullable();
            $table->boolean('auto_disable')->default(false); $table->timestamps();
        });
        Schema::create('network_inventory_devices', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->string('name'); $table->string('ip_address')->nullable();
            $table->string('status')->default('offline'); $table->string('health_status')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable(); $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('network_inventory_health_checks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('network_inventory_device_id'); $table->string('status');
            $table->unsignedInteger('latency_ms')->nullable(); $table->timestamp('checked_at');
        });
        Schema::create('olt_onu_customer_mappings', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('olt_device_id'); $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('ppp_user_id')->nullable(); $table->string('onu_id')->nullable();
            $table->string('onu_serial')->nullable(); $table->string('onu_mac')->nullable(); $table->string('pon_port')->nullable();
            $table->string('onu_type')->nullable(); $table->string('status')->default('unknown');
            $table->decimal('rx_power', 7, 2)->nullable(); $table->decimal('tx_power', 7, 2)->nullable();
            $table->string('onu_ip')->nullable(); $table->timestamp('last_seen_at')->nullable(); $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id(); $table->string('customer_unique_id')->nullable(); $table->string('subject')->nullable();
            $table->string('status')->nullable();
        });
    }
}
