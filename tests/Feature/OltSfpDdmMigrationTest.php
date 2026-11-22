<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OltSfpDdmMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_olt_live_metric_ports_store_only_reported_sfp_ddm_values(): void
    {
        $this->assertTrue(Schema::hasColumn('olt_live_metric_ports', 'sfp_tx_power_dbm'));
        $this->assertTrue(Schema::hasColumn('olt_live_metric_ports', 'sfp_temperature_c'));
        $this->assertTrue(Schema::hasColumn('olt_live_metric_ports', 'sfp_voltage_v'));
        $this->assertTrue(Schema::hasColumn('olt_live_metric_ports', 'sfp_laser_bias_ma'));
        $this->assertTrue(Schema::hasColumn('olt_live_metric_ports', 'ddm_source'));
    }
}
