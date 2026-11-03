<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Models\BandwidthInvoice;
use App\Models\BandwidthReseller;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\SalesQuery;
use App\Models\TeamAttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardLiveMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_live_sales_attendance_and_olt_inventory_metrics(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($admin);

        $this->mock(MikrotikController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('systemOverview')->once()->andReturn([]);
            $mock->shouldReceive('getLivePppTelemetry')->once()->andReturn([
                'status' => 'unavailable',
                'total' => null,
                'successful_routers' => 0,
                'router_count' => 0,
                'routers' => [],
            ]);
        });

        TeamAttendanceRecord::create([
            'user_id' => $admin->id,
            'attendance_date' => today()->toDateString(),
            'check_in_at' => now(),
            'status' => 'present',
        ]);

        SalesQuery::create([
            'prospect_name' => 'New prospect',
            'mobile1' => '01700000001',
            'connection_type' => 'Home (PPPoE)',
            'priority' => 'medium',
            'status' => 'new',
        ]);

        SalesQuery::create([
            'prospect_name' => 'Follow-up prospect',
            'mobile1' => '01700000002',
            'connection_type' => 'Home (PPPoE)',
            'priority' => 'high',
            'follow_up_at' => now()->subMinute(),
            'status' => 'follow_up',
        ]);

        NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'Dashboard OLT Test',
            'status' => 'online',
            'onu_total' => 40,
            'onu_online' => 31,
        ]);

        $bandwidthReseller = BandwidthReseller::create([
            'code' => 'BW-DASH-001',
            'company' => 'Dashboard Bandwidth Test',
            'contact_name' => 'Test Contact',
            'phone' => '01700000003',
            'monthly_due' => 2500,
            'credit_balance' => 0,
            'billing_day' => 10,
            'status' => 'active',
        ]);

        BandwidthInvoice::create([
            'bandwidth_reseller_id' => $bandwidthReseller->id,
            'invoice_no' => 'BW-TEST-0001',
            'billing_month' => now()->startOfMonth()->toDateString(),
            'due_date' => today()->toDateString(),
            'total_amount' => 5000,
            'paid_amount' => 3500,
            'status' => 'partial',
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-card-key="attendance"', false)
            ->assertSee('1 / 1')
            ->assertSee('data-card-key="open_sales_leads"', false)
            ->assertSee('>2</strong>', false)
            ->assertSee('data-card-key="sales_followups_due"', false)
            ->assertSee('data-card-key="new_sales_leads_today"', false)
            ->assertSee('data-card-key="olt_health"', false)
            ->assertSee('data-card-key="onu_health"', false)
            ->assertSee('31 / 40')
            ->assertSee('data-card-key="offline_now"', false)
            ->assertSee('Offline Now')
            ->assertSee('Unavailable')
            ->assertSee('data-card-key="bandwidth_reseller_due"', false)
            ->assertSee('1,500.00')
            ->assertSee('data-card-key="bandwidth_reseller_monthly_bill"', false)
            ->assertSee('2,500.00');
    }

    public function test_offline_now_subtracts_live_online_sessions_only_after_a_complete_router_poll(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($admin);

        $this->mock(MikrotikController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('systemOverview')->once()->andReturn([]);
            $mock->shouldReceive('getLivePppTelemetry')->once()->andReturn([
                'status' => 'ok',
                'total' => 1,
                'successful_routers' => 1,
                'router_count' => 1,
                'routers' => [
                    'router-a' => [
                        'status' => 'ok',
                        'count' => 1,
                        'usernames' => ['online-user'],
                        'synced_at' => now()->toISOString(),
                    ],
                ],
            ]);
        });

        RouterList::create([
            'router_name' => 'router-a',
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-password',
            'action' => 'connected',
        ]);

        $ppp = PPPSecrets::create([
            'router_name' => 'router-a',
            'username' => 'online-user',
            'service' => 'pppoe',
            'status' => 'active',
        ]);

        CustomersInfo::create([
            'customer_unique_id' => 'OFFLINE-METRIC-001',
            'customer_name' => 'Offline Metric Test',
            'status' => 'active',
            'ppp_user_id' => $ppp->id,
        ]);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertSee('data-card-key="offline_now"', false);

        preg_match(
            '/data-card-key="offline_now"[^>]*>.*?<strong class="dashboard-card-value">([^<]+)<\/strong>/s',
            $response->getContent(),
            $matches
        );

        $this->assertSame('0', trim($matches[1] ?? 'missing'), 'A live online PPPoE session must not be counted as offline.');
    }
}
