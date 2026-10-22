<?php

namespace Tests\Feature;

use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerBulkImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
        Storage::fake('local');
    }

    public function test_bulk_import_sample_templates_download_for_all_supported_formats(): void
    {
        $formats = [
            'csv' => ['customer-bulk-import-sample.csv', 'text/csv'],
            'xlsx' => ['customer-bulk-import-sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xls' => ['customer-bulk-import-sample.xls', 'application/vnd.ms-excel'],
        ];

        foreach ($formats as $format => [$filename, $contentType]) {
            $response = $this->get(route('customer-bulk-import.sample', ['format' => $format]));

            $response->assertOk()->assertDownload($filename);
            $this->assertStringContainsString($contentType, (string) $response->headers->get('Content-Type'));
        }
    }

    public function test_bulk_import_requires_manual_customer_id_and_reports_missing_pppoe_users(): void
    {
        $csv = "username,customer_unique_id,customer_name\nmissing-pppoe,FC-MANUAL-01,Test Subscriber\n";
        $this->post(route('customer-bulk-import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
        ])->assertRedirect(route('customer-bulk-import'));

        $this->assertNotEmpty(session('customer_bulk_import_headers'));

        $this->post(route('customer-bulk-import.validate'), [
            'mapping' => [
                'username' => 'username',
                'customer_unique_id' => 'customer_unique_id',
                'customer_name' => 'customer_name',
                'mobile' => '',
                'alternative_mobile' => '',
                'address' => '',
            ],
        ])->assertRedirect(route('customer-bulk-import'));

        $this->assertSame(0, session('customer_bulk_import_summary.valid'));
        $this->assertSame('missing-pppoe', session('customer_bulk_import_issues')[0]['identifier']);
        $this->assertDatabaseMissing('customers_infos', ['customer_unique_id' => 'FC-MANUAL-01']);
    }

    public function test_validated_bulk_import_creates_customer_and_related_billing_records(): void
    {
        $router = RouterList::create([
            'router_name' => 'Bulk Import Test Router',
            'ip_address' => '192.0.2.50',
            'username' => 'router-user',
            'password' => 'router-password',
            'action' => 'connected',
        ]);
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'bulk-import-pppoe',
            'password' => 'test-password',
            'service' => 'pppoe',
            'status' => 'active',
        ]);

        $csv = "username,customer_unique_id,customer_name,mobile,address\nbulk-import-pppoe,FC-MANUAL-02,Bulk Import Subscriber,01700000002,Mirpur\n";
        $this->post(route('customer-bulk-import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
        ])->assertRedirect(route('customer-bulk-import'));

        $this->post(route('customer-bulk-import.validate'), [
            'mapping' => [
                'username' => 'username',
                'customer_unique_id' => 'customer_unique_id',
                'customer_name' => 'customer_name',
                'mobile' => 'mobile',
                'alternative_mobile' => '',
                'address' => 'address',
            ],
        ])->assertRedirect(route('customer-bulk-import'));

        $this->assertSame(1, session('customer_bulk_import_summary.valid'));

        $this->post(route('customer-bulk-import.commit'))
            ->assertRedirect(route('customer-bulk-import'))
            ->assertSessionHas('bulk_import_success');

        $customer = CustomersInfo::where('customer_unique_id', 'FC-MANUAL-02')->firstOrFail();
        $this->assertSame('Bulk Import Subscriber', $customer->customer_name);
        $this->assertSame('01700000002', $customer->mobile);
        $this->assertDatabaseHas('billing_infos', ['customer_bill_unique_id' => 'FC-MANUAL-02']);
        $this->assertDatabaseHas('official_infos', ['customer_office_unique_id' => 'FC-MANUAL-02']);
        $this->assertSame(1, BillingInfo::where('customer_bill_unique_id', 'FC-MANUAL-02')->count());
        $this->assertSame(1, OfficialInfo::where('customer_office_unique_id', 'FC-MANUAL-02')->count());
    }
}
