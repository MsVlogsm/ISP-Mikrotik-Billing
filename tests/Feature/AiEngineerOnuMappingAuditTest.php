<?php

namespace Tests\Feature;

use App\Services\AiEngineerDiagnosticService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiEngineerOnuMappingAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('network_inventory_devices', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->string('name');
            $table->string('ip_address')->nullable();
            $table->string('status')->default('unknown');
            $table->string('health_status')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customers_infos', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->unique();
            $table->string('customer_name')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('ppp_user_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('p_p_p_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('caller_id')->nullable();
            $table->string('last_caller_id')->nullable();
            $table->string('router_name')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('olt_onu_customer_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('olt_device_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('ppp_user_id')->nullable();
            $table->string('onu_id')->nullable();
            $table->string('onu_serial')->nullable();
            $table->string('onu_mac')->nullable();
            $table->string('pon_port')->nullable();
            $table->string('status')->default('unknown');
            $table->decimal('rx_power', 7, 2)->nullable();
            $table->decimal('tx_power', 7, 2)->nullable();
            $table->string('opm_source')->nullable();
            $table->timestamp('opm_last_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('network_inventory_devices')->insert([
            'id'=>1, 'type'=>'olt', 'name'=>'Test OLT', 'ip_address'=>'192.0.2.80',
            'status'=>'online', 'health_status'=>'online', 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        DB::table('p_p_p_secrets')->insert([
            ['id'=>1,'username'=>'ppp-one','caller_id'=>null,'last_caller_id'=>null,'router_name'=>'test-router','status'=>'active','created_at'=>now(),'updated_at'=>now()],
            ['id'=>2,'username'=>'ppp-two','caller_id'=>null,'last_caller_id'=>null,'router_name'=>'test-router','status'=>'active','created_at'=>now(),'updated_at'=>now()],
            ['id'=>3,'username'=>'ppp-other','caller_id'=>null,'last_caller_id'=>null,'router_name'=>'test-router','status'=>'active','created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('customers_infos')->insert([
            ['id'=>1,'customer_unique_id'=>'ONU-CUST-01','customer_name'=>'Customer One','status'=>'active','ppp_user_id'=>1,'deleted_at'=>null,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>2,'customer_unique_id'=>'ONU-CUST-02','customer_name'=>'Customer Two','status'=>'active','ppp_user_id'=>2,'deleted_at'=>null,'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('olt_onu_customer_mappings')->insert([
            ['id'=>1,'olt_device_id'=>1,'customer_id'=>1,'ppp_user_id'=>3,'onu_id'=>'1','onu_serial'=>'SN-ONE','onu_mac'=>'AA:BB:CC:DD:EE:01','pon_port'=>'0/1','status'=>'online','rx_power'=>-20.5,'tx_power'=>2.1,'opm_source'=>'snmp','opm_last_seen_at'=>now(),'last_seen_at'=>now(),'created_at'=>now(),'updated_at'=>now()],
            ['id'=>2,'olt_device_id'=>1,'customer_id'=>1,'ppp_user_id'=>1,'onu_id'=>'2','onu_serial'=>'SN-ONE','onu_mac'=>'aa-bb-cc-dd-ee-01','pon_port'=>'0/1','status'=>'online','rx_power'=>-21.5,'tx_power'=>2.0,'opm_source'=>'snmp','opm_last_seen_at'=>now()->subHours(48),'last_seen_at'=>now()->subHours(48),'created_at'=>now()->subHours(48),'updated_at'=>now()->subHours(48)],
            ['id'=>3,'olt_device_id'=>1,'customer_id'=>null,'ppp_user_id'=>null,'onu_id'=>'3','onu_serial'=>null,'onu_mac'=>null,'pon_port'=>'0/2','status'=>'unknown','rx_power'=>null,'tx_power'=>null,'opm_source'=>null,'opm_last_seen_at'=>null,'last_seen_at'=>null,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>4,'olt_device_id'=>1,'customer_id'=>2,'ppp_user_id'=>2,'onu_id'=>'4','onu_serial'=>'SN-TWO','onu_mac'=>'AA:BB:CC:DD:EE:04','pon_port'=>'0/2','status'=>'online','rx_power'=>-23.0,'tx_power'=>null,'opm_source'=>null,'opm_last_seen_at'=>null,'last_seen_at'=>now(),'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function test_onu_audit_flags_identity_duplicates_mismatches_and_stale_optical_data_read_only(): void
    {
        $mappingCountBefore = DB::table('olt_onu_customer_mappings')->count();
        $mappingBefore = DB::table('olt_onu_customer_mappings')->where('id', 2)->first();

        $audit = (new AiEngineerDiagnosticService())->onuMappingAudit(30);

        $this->assertTrue($audit['available']);
        $this->assertTrue($audit['read_only']);
        $this->assertSame(4, $audit['summary']['total_mappings']);
        $this->assertSame(3, $audit['summary']['linked_mappings']);
        $this->assertSame(1, $audit['summary']['unmapped']);
        $this->assertSame(1, $audit['summary']['missing_identity_records']);
        $this->assertSame(0, $audit['summary']['invalid_mac_records']);
        $this->assertSame(1, $audit['summary']['ppp_link_mismatches']);
        $this->assertSame(1, $audit['summary']['duplicate_mac_groups']);
        $this->assertSame(1, $audit['summary']['duplicate_serial_groups']);
        $this->assertSame(1, $audit['summary']['duplicate_customer_groups']);
        $this->assertSame(1, $audit['summary']['optical_stale']);
        $this->assertSame(1, $audit['summary']['optical_unknown_timestamp']);
        $this->assertSame(1, $audit['summary']['optical_missing_reading']);
        $this->assertSame(1, $audit['summary']['optical_unknown_source']);

        $types = array_column($audit['findings'], 'type');
        $this->assertContains('ppp_link_mismatch', $types);
        $this->assertContains('duplicate_onu_mac', $types);
        $this->assertContains('optical_stale', $types);
        $this->assertContains('optical_unknown_timestamp', $types);
        $this->assertContains('unmapped_onu', $types);
        $this->assertSame($mappingCountBefore, DB::table('olt_onu_customer_mappings')->count());
        $this->assertEquals($mappingBefore, DB::table('olt_onu_customer_mappings')->where('id', 2)->first());
        $this->assertTrue(str_contains($audit['note'], 'vendor/model'));
    }

    public function test_onu_audit_flags_malformed_mac_without_treating_it_as_a_valid_duplicate(): void
    {
        DB::table('olt_onu_customer_mappings')->insert([
            'id'=>5,'olt_device_id'=>1,'customer_id'=>2,'ppp_user_id'=>2,
            'onu_id'=>'5','onu_serial'=>'SN-BAD-MAC','onu_mac'=>'ZZ:11:22:33:44:55',
            'pon_port'=>'0/2','status'=>'unknown','rx_power'=>null,'tx_power'=>null,
            'opm_source'=>null,'opm_last_seen_at'=>null,'last_seen_at'=>null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $audit = (new AiEngineerDiagnosticService())->onuMappingAudit(30);

        $this->assertSame(1, $audit['summary']['invalid_mac_records']);
        $this->assertContains('invalid_onu_mac', array_column($audit['findings'], 'type'));
        $this->assertSame(1, $audit['summary']['duplicate_mac_groups']);
    }

    public function test_onu_audit_handles_missing_mapping_table_safely(): void
    {
        Schema::drop('olt_onu_customer_mappings');

        $audit = (new AiEngineerDiagnosticService())->onuMappingAudit();

        $this->assertFalse($audit['available']);
        $this->assertTrue($audit['read_only']);
        $this->assertSame([], $audit['findings']);
    }
}
