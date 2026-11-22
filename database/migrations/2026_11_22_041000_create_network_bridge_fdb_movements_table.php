<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('network_bridge_fdb_movements')) {
            return;
        }

        Schema::create('network_bridge_fdb_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_inventory_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('network_bridge_fdb_snapshots')->cascadeOnDelete();
            $table->foreignId('previous_snapshot_id')->nullable()->constrained('network_bridge_fdb_snapshots')->nullOnDelete();
            $table->string('mac_address', 17);
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->string('from_interface', 160)->nullable();
            $table->string('to_interface', 160)->nullable();
            $table->unsignedInteger('from_if_index')->nullable();
            $table->unsignedInteger('to_if_index')->nullable();
            $table->timestamp('moved_at');
            $table->unsignedSmallInteger('moves_in_10m')->default(1);
            $table->boolean('is_flapping')->default(false);
            $table->timestamps();

            $table->index(['network_inventory_device_id', 'moved_at'], 'bridge_fdb_movement_device_time_idx');
            $table->index(['network_inventory_device_id', 'mac_address', 'moved_at'], 'bridge_fdb_movement_device_mac_time_idx');
            $table->index(['is_flapping', 'moved_at'], 'bridge_fdb_movement_flapping_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_bridge_fdb_movements');
    }
};
