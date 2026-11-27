<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncOltOnuMappingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RouterList::create([
            'router_name' => 'MatcherRouter', 'ip_address' => '127.0.0.1',
            'username' => 'matcher-user', 'password' => 'matcher-password',
            'action' => 'disconnected',
        ]);
    }

    private function olt(): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'VSOL matcher test', 'vendor' => 'VSOL',
            'model' => 'V1601E04-DP', 'status' => 'online', 'health_status' => 'online',
            'is_active' => true,
        ]);
    }

    private function customer(string $cid, string $username, string $status, string $mac): array
    {
        $ppp = PPPSecrets::create([
            'router_name' => 'MatcherRouter', 'username' => $username, 'password' => 'test-secret',
            'service' => 'pppoe', 'profile' => 'Home', 'status' => $status,
            'caller_id' => '', 'last_caller_id' => $mac,
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => $cid, 'customer_name' => 'Customer '.$cid,
            'status' => 'active', 'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $ppp];
    }

    private function fakeAdapter(
        NetworkInventoryDevice $olt,
        string $mac,
        array $clientMacs = [],
        array $extraOnuFields = [],
        string $resultStatus = 'ok',
        bool $includeReadOnlyMetadata = true,
        array $additionalOnus = [],
    ): void {
        $manager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $manager->shouldReceive('read')->once()->with(\Mockery::on(
            fn ($device) => $device instanceof NetworkInventoryDevice && $device->id === $olt->id
        ))->andReturn([
            'ok' => true, 'status' => $resultStatus, 'message' => 'test response',
            'meta' => $includeReadOnlyMetadata
                ? ['read_only' => true, 'writes_performed' => false]
                : ['read_only' => false, 'writes_performed' => false],
            'onus' => array_merge([array_merge([
                'onu_id' => '25', 'pon_port' => '1', 'onu_mac' => $mac,
                'client_macs' => $clientMacs,
                'status' => 'online', 'snmp_index' => '1.25',
            ], $extraOnuFields)], $additionalOnus),
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $manager);
    }

    public function test_dry_run_does_not_backfill_the_mac_ledger(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c6';
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'onu_serial' => 'DRY-RUN-SERIAL',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('onu_mac_ledgers', 0);
    }

    public function test_exact_mac_match_prefers_one_active_ppp_customer_over_removed_legacy_record(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        [$removedCustomer, $removedPpp] = $this->customer('BT0027', 'm.shakil', 'removed', $mac);
        [$activeCustomer, $activePpp] = $this->customer('BT0006', 'bt.shakil', 'active', $mac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertSame($activeCustomer->id, $mapping->customer_id);
        $this->assertSame($activePpp->id, $mapping->ppp_user_id);
        $this->assertNotSame($removedCustomer->id, $mapping->customer_id);
        $this->assertNotSame($removedPpp->id, $mapping->ppp_user_id);
    }

    public function test_unique_active_ppp_client_mac_learned_behind_onu_maps_customer(): void
    {
        $olt = $this->olt();
        $onuMac = '00:11:46:95:1c:c5';
        $clientMac = 'a4:b1:c1:22:33:44';
        [$customer, $ppp] = $this->customer('BTFDB01', 'fdb.unique.customer', 'active', $clientMac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $onuMac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $onuMac, [$clientMac]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertSame($customer->id, $mapping->customer_id);
        $this->assertSame($ppp->id, $mapping->ppp_user_id);
    }

    public function test_multiple_active_ppp_client_macs_on_same_onu_remain_unmapped(): void
    {
        $olt = $this->olt();
        $onuMac = '00:11:46:95:1c:c5';
        $clientMac = 'a4:b1:c1:22:33:55';
        $this->customer('BTFDB02', 'fdb.first.customer', 'active', $clientMac);
        $this->customer('BTFDB03', 'fdb.second.customer', 'active', $clientMac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $onuMac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $onuMac, [$clientMac]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertNull($mapping->customer_id);
        $this->assertNull($mapping->ppp_user_id);
    }

    public function test_multiple_active_ppp_accounts_with_same_onu_mac_remain_unmapped(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $this->customer('BT0006', 'bt.shakil', 'active', $mac);
        $this->customer('BT0044', 'other.active.shakil', 'active', $mac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertNull($mapping->customer_id);
        $this->assertNull($mapping->ppp_user_id);
    }

    public function test_opm_timestamp_advances_only_when_fresh_optical_values_are_returned(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $measuredAt = now()->subHours(2);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => $mac,
            'status' => 'online',
            'rx_power' => -26.4,
            'tx_power' => 1.7,
            'opm_source' => 'vsol-snmp-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);
        $this->fakeAdapter($olt, $mac, [], [
            'rx_power' => -18.25,
            'tx_power' => 2.15,
            'opm_source' => 'vsol-snmp-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertEquals(-18.25, (float) $mapping->rx_power);
        $this->assertEquals(2.15, (float) $mapping->tx_power);
        $this->assertTrue($mapping->opm_last_seen_at->greaterThan($measuredAt));
    }


    public function test_ai_optical_power_list_exposes_the_opm_measurement_timestamp(): void
    {
        $olt = $this->olt();
        $measuredAt = now()->subMinutes(20);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => '00:11:46:95:1c:c5',
            'status' => 'online',
            'rx_power' => -21.2,
            'tx_power' => 1.95,
            'opm_source' => 'test-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);

        $row = app(\App\Services\AiEngineerDiagnosticService::class)->opticalPowerList(10)[0];

        $this->assertSame('test-opm', $row['opm_source']);
        $this->assertSame($measuredAt->toIso8601String(), $row['opm_measured_at']);
        $this->assertSame(-21.2, (float) $row['rx']);
        $this->assertSame(1.95, (float) $row['tx']);
    }

    public function test_status_only_poll_does_not_refresh_old_opm_timestamp_or_values(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $measuredAt = now()->subHours(12);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => $mac,
            'status' => 'online',
            'rx_power' => -26.4,
            'tx_power' => 1.7,
            'opm_source' => 'vsol-snmp-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertEquals(-26.4, (float) $mapping->rx_power);
        $this->assertEquals(1.7, (float) $mapping->tx_power);
        $this->assertEquals($measuredAt->toDateTimeString(), $mapping->opm_last_seen_at->toDateTimeString());
    }

    public function test_complete_read_only_poll_warns_about_absent_online_onu_without_changing_mapping(): void
    {
        $olt = $this->olt();
        $lastSeen = now()->subHours(8);
        $stale = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '62',
            'pon_port' => '1',
            'onu_mac' => '00:d5:9e:9d:34:74',
            'status' => 'online',
            'last_seen_at' => $lastSeen,
            'notes' => 'Keep historical note.',
        ]);

        $this->fakeAdapter($olt, '00:11:46:95:1c:c5');
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
            ->expectsOutputToContain('ONU 62 / 00:d5:9e:9d:34:74 is stored as online but absent from this complete read-only poll')
            ->assertExitCode(0);

        $stale->refresh();
        $this->assertSame('online', $stale->status);
        $this->assertSame($lastSeen->toDateTimeString(), $stale->last_seen_at->toDateTimeString());
        $this->assertSame('Keep historical note.', $stale->notes);
    }

    public function test_complete_verified_read_only_poll_records_olt_dashboard_snapshot(): void
    {
        $olt = $this->olt();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -28.2,
            'tx_power' => 1.65,
            'opm_source' => 'test-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 1);
        $snapshot = \App\Models\OltMonitoringSnapshot::where('olt_device_id', $olt->id)->firstOrFail();

        $this->assertSame(1, (int) $snapshot->onu_total);
        $this->assertSame(1, (int) $snapshot->onu_online);
        $this->assertSame(0, (int) $snapshot->onu_offline);
        $this->assertSame(1, (int) $snapshot->rx_sample_count);
        $this->assertSame(1, (int) $snapshot->signal_critical);
        $this->assertSame(0, (int) $snapshot->signal_good);
        $this->assertEquals(-28.2, (float) $snapshot->avg_rx_power);
    }

    public function test_repeated_polls_update_a_snapshot_window_without_sliding_its_timestamp(): void
    {
        $olt = $this->olt();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -18.5,
            'tx_power' => 1.7,
            'opm_source' => 'test-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);
        $first = \App\Models\OltMonitoringSnapshot::where('olt_device_id', $olt->id)->firstOrFail();
        $firstSampledAt = $first->sampled_at->toDateTimeString();

        // A second poll inside the configured 5-minute bucket must refresh metrics
        // but retain the first sample timestamp rather than sliding the bucket.
        $this->travel(2)->minutes();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -19.0,
            'tx_power' => 1.8,
            'opm_source' => 'test-opm',
        ]);
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 1);
        $this->assertSame($firstSampledAt, $first->fresh()->sampled_at->toDateTimeString());
        $this->assertEquals(-19.0, (float) $first->fresh()->avg_rx_power);

        // Once the window has elapsed, the next verified poll creates a new point.
        $this->travel(4)->minutes();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -20.0,
            'tx_power' => 1.9,
            'opm_source' => 'test-opm',
        ]);
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 2);
    }

    public function test_location_conflict_does_not_move_an_onu_mapping_onto_an_occupied_pon_index(): void
    {
        $olt = $this->olt();
        $stableMac = '70:a5:6a:79:72:5e';
        $occupiedMac = 'a2:4f:a2:72:fe:c0';

        $stableMapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '7',
            'pon_port' => '1',
            'onu_mac' => $stableMac,
            'status' => 'online',
        ]);
        $occupiedMapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '8',
            'pon_port' => '2',
            'onu_mac' => $occupiedMac,
            'status' => 'offline',
        ]);

        $this->fakeAdapter($olt, $stableMac, [], [
            'onu_id' => '8',
            'pon_port' => '2',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
            ->expectsOutputToContain("ONU 8: live PON 2/ONU 8 is already occupied by mapping #{$occupiedMapping->id}; existing mapping #{$stableMapping->id} was left unchanged for manual review.")
            ->assertExitCode(0);

        $stableMapping->refresh();
        $occupiedMapping->refresh();

        $this->assertSame('1', (string) $stableMapping->pon_port);
        $this->assertSame('7', (string) $stableMapping->onu_id);
        $this->assertSame($stableMac, $stableMapping->onu_mac);
        $this->assertSame('2', (string) $occupiedMapping->pon_port);
        $this->assertSame('8', (string) $occupiedMapping->onu_id);
        $this->assertSame($occupiedMac, $occupiedMapping->onu_mac);
        $this->assertDatabaseCount('olt_monitoring_snapshots', 0);
    }

    public function test_unique_index_race_skips_one_mapping_and_continues_refreshing_other_onus(): void
    {
        $olt = $this->olt();
        $stableMac = '70:a5:6a:79:72:5e';
        $nextMac = 'a4:b1:c1:22:33:66';
        [$customer, $ppp] = $this->customer('BTRACE01', 'race.existing.customer', 'active', $stableMac);
        $stableMapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => $stableMac,
            'status' => 'online',
            'rx_power' => -25.0,
            'tx_power' => 1.8,
            'opm_last_seen_at' => now()->subHours(2),
        ]);

        $eventName = 'eloquent.saving: '.OltOnuCustomerMapping::class;
        $competingMappingId = null;
        Event::listen($eventName, function ($mapping) use ($stableMapping, $olt, &$competingMappingId): void {
            if ($competingMappingId !== null || ! $mapping->is($stableMapping)) {
                return;
            }

            $competingMappingId = DB::table('olt_onu_customer_mappings')->insertGetId([
                'olt_device_id' => $olt->id,
                'onu_id' => '27',
                'pon_port' => '2',
                'onu_mac' => 'a4:b1:c1:22:33:77',
                'status' => 'online',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw new UniqueConstraintViolationException(
                'mysql',
                'update `olt_onu_customer_mappings` set `pon_port` = ?, `onu_id` = ? where `id` = ?',
                ['2', '27', $stableMapping->id],
                new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '3-2-27'")
            );
        });

        $this->fakeAdapter($olt, $stableMac, [], [
            'onu_id' => '27',
            'pon_port' => '2',
            'rx_power' => -22.1,
            'tx_power' => 2.2,
            'opm_source' => 'test-opm',
        ], additionalOnus: [[
            'onu_id' => '26',
            'pon_port' => '1',
            'onu_mac' => $nextMac,
            'client_macs' => [],
            'status' => 'online',
            'snmp_index' => '1.26',
            'rx_power' => -18.5,
            'tx_power' => 2.1,
            'opm_source' => 'test-opm',
        ]]);

        try {
            $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
                ->expectsOutputToContain('ONU 27: duplicate OLT/PON/ONU index conflict')
                ->assertExitCode(0);
            $this->assertNotNull($competingMappingId);
        } finally {
            Event::forget($eventName);
        }

        $stableMapping->refresh();
        $this->assertSame('1', (string) $stableMapping->pon_port);
        $this->assertSame('25', (string) $stableMapping->onu_id);
        $this->assertSame($customer->id, $stableMapping->customer_id);
        $this->assertSame($ppp->id, $stableMapping->ppp_user_id);

        $nextMapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)
            ->where('pon_port', '1')->where('onu_id', '26')->firstOrFail();
        $this->assertEquals(-18.5, (float) $nextMapping->rx_power);
        $this->assertEquals(2.1, (float) $nextMapping->tx_power);
        $this->assertNotNull($nextMapping->opm_last_seen_at);
        $this->assertDatabaseCount('olt_monitoring_snapshots', 0);
    }

    public function test_partial_or_unverified_poll_does_not_claim_an_absent_onu_is_stale(): void
    {
        $olt = $this->olt();
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '62',
            'pon_port' => '1',
            'onu_mac' => '00:d5:9e:9d:34:74',
            'status' => 'online',
            'last_seen_at' => now()->subHours(8),
        ]);

        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [], 'partial');
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
            ->doesntExpectOutputToContain('ONU 62 / 00:d5:9e:9d:34:74 is stored as online')
            ->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 0);
    }

}
