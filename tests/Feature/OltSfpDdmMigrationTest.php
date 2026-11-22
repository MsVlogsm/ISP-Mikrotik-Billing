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
    public function test_compatibility_migration_rollback_preserves_existing_sfp_columns(): void
    {
        $migration = require database_path('migrations/2026_11_22_040000_add_sfp_ddm_metrics_to_olt_live_metric_ports.php');

        $migration->down();

        foreach (['sfp_tx_power_dbm', 'sfp_temperature_c', 'sfp_voltage_v', 'sfp_laser_bias_ma', 'ddm_source'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('olt_live_metric_ports', $column),
                $column.' must remain available because it may predate this compatibility migration.'
            );
        }
    }

}
