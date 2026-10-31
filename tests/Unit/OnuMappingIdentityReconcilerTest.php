<?php

namespace Tests\Unit;

use App\Services\Olt\OnuMappingIdentityReconciler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OnuMappingIdentityReconcilerTest extends TestCase
{
    public function test_maps_by_stable_mac_and_reports_rekeys_without_reassigning_customer_ids(): void
    {
        $plan = (new OnuMappingIdentityReconciler())->plan([
            ['onu_id' => '34', 'pon_port' => '1', 'onu_mac' => '50:5b:1d:3a:90:c3', 'status' => 'online'],
            ['onu_id' => '0', 'pon_port' => '1', 'onu_mac' => '00:11:46:b6:dd:42', 'status' => 'online'],
        ], [
            ['id' => 10, 'onu_id' => '36', 'onu_mac' => '50:5B:1D:3A:90:C3', 'customer_id' => 100, 'ppp_user_id' => 200, 'pon_port' => '1', 'status' => 'online'],
            ['id' => 11, 'onu_id' => '1', 'onu_mac' => '00:11:46:b6:dd:42', 'customer_id' => null, 'ppp_user_id' => null, 'pon_port' => '1', 'status' => 'online'],
        ]);
        $this->assertCount(2, $plan['matched']);
        $this->assertSame('34', $plan['matched'][0]['target_onu_id']);
        $this->assertSame('0', $plan['matched'][1]['target_onu_id']);
        $this->assertSame(10, $plan['matched'][0]['mapping_id']);
        $this->assertSame([], $plan['stale']);
        $this->assertSame([], $plan['new']);
        $this->assertSame([], $plan['blockers']);
    }

    public function test_linked_stale_mapping_is_preserved_in_archive_plan_and_unlinked_stale_is_retained(): void
    {
        $plan = (new OnuMappingIdentityReconciler())->plan([
            ['onu_id' => '1', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:55', 'status' => 'online'],
        ], [
            ['id' => 2, 'onu_id' => '7', 'onu_mac' => '80:14:a8:65:48:e8', 'customer_id' => null, 'ppp_user_id' => null],
            ['id' => 3, 'onu_id' => '6', 'onu_mac' => 'aa:bb:cc:dd:ee:ff', 'customer_id' => 300, 'ppp_user_id' => 400],
        ]);
        $this->assertCount(2, $plan['stale']);
        $this->assertTrue($plan['stale'][1]['linked']);
        $this->assertCount(1, $plan['blockers']);
        $this->assertStringContainsString('row 3', $plan['blockers'][0]);
        $this->assertCount(1, $plan['new']);
    }

    public function test_rejects_duplicate_live_mac_or_onu_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OnuMappingIdentityReconciler())->plan([
            ['onu_id' => '1', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:55', 'status' => 'online'],
            ['onu_id' => '2', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:55', 'status' => 'online'],
        ], []);
    }

    public function test_rejects_duplicate_stored_mac(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OnuMappingIdentityReconciler())->plan([
            ['onu_id' => '1', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:55', 'status' => 'online'],
        ], [
            ['id' => 1, 'onu_id' => '4', 'onu_mac' => '00:11:22:33:44:55'],
            ['id' => 2, 'onu_id' => '5', 'onu_mac' => '00:11:22:33:44:55'],
        ]);
    }
}
