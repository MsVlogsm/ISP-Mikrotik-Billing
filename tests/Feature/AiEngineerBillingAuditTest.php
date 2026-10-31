<?php

namespace Tests\Feature;

use App\Services\AiEngineerDiagnosticService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiEngineerBillingAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('customers_infos', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->unique();
            $table->string('customer_name')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('billing_infos', function (Blueprint $table) {
            $table->id();
            $table->string('customer_bill_unique_id')->nullable();
            $table->decimal('total_amount', 11, 2)->default(0);
            $table->decimal('paid_amount', 11, 2)->default(0);
            $table->decimal('due_amount', 11, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('collection_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('customer_collection_unique_id')->nullable();
            $table->timestamp('collection_date');
            $table->decimal('collection_amount', 11, 2)->default(0);
            $table->string('payment_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
    }

    public function test_billing_audit_reports_mismatches_and_orphan_records_without_writing_data(): void
    {
        $this->customer('BT001', 'Matching customer');
        $this->customer('BT002', 'Mismatch customer');
        $this->customer('BT003', 'Missing billing customer');

        DB::table('billing_infos')->insert([
            ['customer_bill_unique_id'=>'BT001','total_amount'=>100,'paid_amount'=>100,'due_amount'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['customer_bill_unique_id'=>'BT002','total_amount'=>100,'paid_amount'=>80,'due_amount'=>20,'created_at'=>now(),'updated_at'=>now()],
            ['customer_bill_unique_id'=>'ORPHAN-BILL','total_amount'=>100,'paid_amount'=>0,'due_amount'=>100,'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('collection_summaries')->insert([
            ['customer_collection_unique_id'=>'BT001','collection_date'=>now(),'collection_amount'=>100,'payment_status'=>'paid','payment_method'=>'cash','created_at'=>now(),'updated_at'=>now()],
            ['customer_collection_unique_id'=>'BT002','collection_date'=>now(),'collection_amount'=>50,'payment_status'=>'paid','payment_method'=>'cash','created_at'=>now(),'updated_at'=>now()],
            ['customer_collection_unique_id'=>'BT001','collection_date'=>now(),'collection_amount'=>25,'payment_status'=>'pending','payment_method'=>'bkash','created_at'=>now(),'updated_at'=>now()],
            ['customer_collection_unique_id'=>'ORPHAN-COLLECTION','collection_date'=>now(),'collection_amount'=>15,'payment_status'=>'paid','payment_method'=>'cash','created_at'=>now(),'updated_at'=>now()],
        ]);

        $billingBefore = DB::table('billing_infos')->count();
        $collectionsBefore = DB::table('collection_summaries')->count();

        $audit = (new AiEngineerDiagnosticService())->billingAudit(25);

        $this->assertTrue($audit['available']);
        $this->assertTrue($audit['read_only']);
        $this->assertSame(1, $audit['summary']['payment_mismatches']);
        $this->assertSame(1, $audit['summary']['orphan_billing']);
        $this->assertSame(1, $audit['summary']['orphan_collections']);
        $this->assertSame(1, $audit['summary']['missing_active_billing']);
        $this->assertSame(1, $audit['summary']['pending_collections_current_month']);
        $this->assertContains('payment_mismatch', array_column($audit['findings'], 'type'));
        $this->assertSame($billingBefore, DB::table('billing_infos')->count());
        $this->assertSame($collectionsBefore, DB::table('collection_summaries')->count());
    }

    public function test_billing_audit_does_not_flag_a_matching_payment_ledger(): void
    {
        $this->customer('BT010', 'Settled customer');
        DB::table('billing_infos')->insert([
            'customer_bill_unique_id'=>'BT010','total_amount'=>500,'paid_amount'=>500,'due_amount'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('collection_summaries')->insert([
            'customer_collection_unique_id'=>'BT010','collection_date'=>now(),'collection_amount'=>500,
            'payment_status'=>'paid','payment_method'=>'nagad','created_at'=>now(),'updated_at'=>now(),
        ]);

        $audit = (new AiEngineerDiagnosticService())->billingAudit();

        $this->assertSame(0, $audit['summary']['payment_mismatches']);
        $this->assertSame(0, $audit['issues_total']);
    }

    private function customer(string $id, string $name): void
    {
        DB::table('customers_infos')->insert([
            'customer_unique_id' => $id,
            'customer_name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
