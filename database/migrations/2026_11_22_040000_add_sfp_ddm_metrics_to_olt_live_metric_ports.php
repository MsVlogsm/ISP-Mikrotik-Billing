<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olt_live_metric_ports')) {
            return;
        }

        Schema::table('olt_live_metric_ports', function (Blueprint $table): void {
            if (! Schema::hasColumn('olt_live_metric_ports', 'sfp_tx_power_dbm')) {
                $table->decimal('sfp_tx_power_dbm', 8, 3)->nullable();
            }
            if (! Schema::hasColumn('olt_live_metric_ports', 'sfp_temperature_c')) {
                $table->decimal('sfp_temperature_c', 7, 2)->nullable();
            }
            if (! Schema::hasColumn('olt_live_metric_ports', 'sfp_voltage_v')) {
                $table->decimal('sfp_voltage_v', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('olt_live_metric_ports', 'sfp_laser_bias_ma')) {
                $table->decimal('sfp_laser_bias_ma', 9, 3)->nullable();
            }
            if (! Schema::hasColumn('olt_live_metric_ports', 'ddm_source')) {
                $table->string('ddm_source', 48)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('olt_live_metric_ports')) {
            return;
        }

        Schema::table('olt_live_metric_ports', function (Blueprint $table): void {
            foreach (['sfp_tx_power_dbm', 'sfp_temperature_c', 'sfp_voltage_v', 'sfp_laser_bias_ma', 'ddm_source'] as $column) {
                if (Schema::hasColumn('olt_live_metric_ports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
