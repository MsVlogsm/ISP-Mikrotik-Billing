<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Models\NetworkInventoryDevice;
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
            ->assertSee('31 / 40');
    }
}
