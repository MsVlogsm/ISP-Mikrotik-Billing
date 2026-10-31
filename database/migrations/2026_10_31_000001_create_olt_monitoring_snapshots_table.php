<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_monitoring_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_device_id')
                ->constrained('network_inventory_devices')
                ->cascadeOnDelete();
            $table->timestamp('sampled_at');

            $table->unsignedInteger('onu_total')->default(0);
            $table->unsignedInteger('onu_online')->default(0);
            $table->unsignedInteger('onu_offline')->default(0);
            $table->unsignedInteger('onu_unknown')->default(0);
            $table->unsignedInteger('mapped_onus')->default(0);
            $table->unsignedInteger('mapped_customers')->default(0);

            $table->unsignedInteger('rx_sample_count')->default(0);
            $table->unsignedInteger('signal_good')->default(0);
            $table->unsignedInteger('signal_warning')->default(0);
            $table->unsignedInteger('signal_critical')->default(0);
            $table->unsignedInteger('signal_no_sample')->default(0);
            $table->decimal('avg_rx_power', 7, 2)->nullable();
            $table->decimal('min_rx_power', 7, 2)->nullable();
            $table->unsignedInteger('avg_latency_ms')->nullable();

            $table->index(['olt_device_id', 'sampled_at'], 'olt_snapshot_device_sampled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_monitoring_snapshots');
    }
};
