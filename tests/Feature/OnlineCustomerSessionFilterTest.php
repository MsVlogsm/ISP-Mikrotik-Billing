<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\OnlineCustomerSessionFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlineCustomerSessionFilterTest extends TestCase
{
    use RefreshDatabase;

    private function router(string $name): RouterList
    {
        return RouterList::create([
            'router_name' => $name,
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-password',
            'action' => 'connected',
        ]);
    }

    private function customer(string $cid, string $username, string $routerName, string $customerStatus = 'active', string $pppStatus = 'active'): CustomersInfo
    {
        $secret = PPPSecrets::create([
            'router_name' => $routerName,
            'username' => $username,
            'service' => 'pppoe',
            'status' => $pppStatus,
        ]);

        return CustomersInfo::create([
            'customer_unique_id' => $cid,
            'customer_name' => $cid,
            'ppp_user_id' => $secret->id,
            'status' => $customerStatus,
        ]);
    }

    public function test_online_filter_only_counts_active_customers_with_active_ppp_sessions_on_the_matching_router(): void
    {
        $this->router('router-a');
        $this->router('router-b');

        $online = $this->customer('CID-ONLINE', 'Alice', 'router-a');
        $numericUsername = $this->customer('CID-NUMERIC', '12345', 'router-a');
        $this->customer('CID-OFFLINE', 'bob', 'router-a');
        $this->customer('CID-INACTIVE', 'charlie', 'router-a', 'inactive', 'active');
        $this->customer('CID-PPP-DISABLED', 'david', 'router-a', 'active', 'disabled');
        $this->customer('CID-WRONG-ROUTER', 'alice', 'router-b');

        $snapshot = [
            'router-a' => ['alice' => true, '12345' => true, 'charlie' => true, 'david' => true],
        ];
        $query = app(OnlineCustomerSessionFilter::class)->apply(CustomersInfo::query(), $snapshot, 1);

        $this->assertSame(
            [$online->customer_unique_id, $numericUsername->customer_unique_id],
            $query->orderBy('id')->pluck('customer_unique_id')->all()
        );
    }

    public function test_failed_live_session_reads_never_fall_back_to_enabled_account_counts(): void
    {
        $this->router('router-a');
        $this->customer('CID-NOT-ONLINE', 'alice', 'router-a');

        $query = app(OnlineCustomerSessionFilter::class)->apply(
            CustomersInfo::query(),
            ['router-a' => ['alice' => true]],
            0
        );

        $this->assertSame(0, $query->count());
    }

    public function test_telemetry_snapshot_normalizes_usernames_and_ignores_unavailable_routers(): void
    {
        $snapshot = app(OnlineCustomerSessionFilter::class)->fromTelemetry([
            'successful_routers' => 1,
            'router_count' => 2,
            'routers' => [
                'router-a' => ['status' => 'ok', 'usernames' => [' Alice ', 'BOB', 'Alice']],
                'router-b' => ['status' => 'unavailable', 'usernames' => ['ghost']],
            ],
        ]);

        $this->assertSame(1, $snapshot['successful_routers']);
        $this->assertSame(['alice' => true, 'bob' => true], $snapshot['users_by_router']['router-a']);
        $this->assertArrayNotHasKey('router-b', $snapshot['users_by_router']);
    }
}
