<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('olt_live_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
            $table->timestamp('sampled_at')->index();
            $table->string('sys_descr', 255)->nullable();
            $table->unsignedBigInteger('uptime_ticks')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->decimal('memory_percent', 5, 2)->nullable();
            $table->decimal('chassis_temp_c', 6, 2)->nullable();
            $table->unsignedInteger('interface_count')->default(0);
            $table->unsignedSmallInteger('pon_up')->default(0);
            $table->unsignedSmallInteger('pon_down')->default(0);
            $table->unsignedSmallInteger('uplink_up')->default(0);
            $table->unsignedSmallInteger('uplink_down')->default(0);
            $table->unsignedInteger('onu_total')->default(0);
            $table->unsignedInteger('onu_online')->default(0);
            $table->unsignedInteger('onu_offline')->default(0);
            $table->unsignedInteger('onu_unknown')->default(0);
            $table->unsignedInteger('mapped_customers')->default(0);
            $table->unsignedInteger('capacity_total')->nullable();
            $table->unsignedInteger('poll_duration_ms')->nullable();
            $table->string('poll_status', 20)->default('success');
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->index(['olt_device_id', 'sampled_at'], 'olt_live_snapshot_device_time_idx');
        });

        Schema::create('olt_live_metric_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('olt_live_metric_snapshots')->cascadeOnDelete();
            $table->foreignId('olt_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
            $table->unsignedInteger('if_index');
            $table->string('interface_name', 120);
            $table->string('role', 30)->default('other');
            $table->string('oper_status', 16)->default('unknown');
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();
            $table->decimal('in_bps', 20, 2)->nullable();
            $table->decimal('out_bps', 20, 2)->nullable();
            $table->unsignedInteger('onu_total')->default(0);
            $table->unsignedInteger('onu_online')->default(0);
            $table->unsignedInteger('rx_sample_count')->default(0);
            $table->decimal('avg_rx_dbm', 7, 2)->nullable();
            $table->decimal('avg_tx_dbm', 7, 2)->nullable();
            $table->decimal('avg_distance_m', 12, 2)->nullable();
            $table->timestamp('sampled_at')->index();
            $table->timestamps();
            $table->unique(['snapshot_id', 'if_index'], 'olt_live_port_snapshot_ifindex_unique');
            $table->index(['olt_device_id', 'if_index', 'sampled_at'], 'olt_live_port_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_live_metric_ports');
        Schema::dropIfExists('olt_live_metric_snapshots');
    }
};
