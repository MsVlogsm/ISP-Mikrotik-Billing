<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('network_bridge_fdb_snapshots')) {
            Schema::create('network_bridge_fdb_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_inventory_device_id')
                    ->constrained('network_inventory_devices')
                    ->cascadeOnDelete();
                $table->timestamp('sampled_at');
                $table->string('source', 16)->nullable();
                $table->string('status', 24)->default('unknown');
                $table->unsignedInteger('entry_count')->default(0);
                $table->unsignedInteger('learned_count')->default(0);
                $table->unsignedInteger('other_count')->default(0);
                $table->unsignedInteger('self_count')->default(0);
                $table->unsignedInteger('management_count')->default(0);
                $table->unsignedInteger('invalid_count')->default(0);
                $table->unsignedInteger('poll_duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['network_inventory_device_id', 'sampled_at'], 'bridge_fdb_device_sampled_index');
                $table->index(['network_inventory_device_id', 'status'], 'bridge_fdb_device_status_index');
            });
        }

        if (! Schema::hasTable('network_bridge_fdb_entries')) {
            Schema::create('network_bridge_fdb_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('snapshot_id')
                    ->constrained('network_bridge_fdb_snapshots')
                    ->cascadeOnDelete();
                $table->string('mac_address', 17);
                $table->unsignedSmallInteger('vlan_id')->nullable();
                $table->unsignedInteger('fdb_id')->nullable();
                $table->unsignedInteger('bridge_port')->nullable();
                $table->unsignedInteger('if_index')->nullable();
                $table->string('interface_name', 160)->nullable();
                $table->string('entry_status', 24)->default('unknown');
                $table->unsignedTinyInteger('status_code')->nullable();
                $table->string('source', 16);
                $table->timestamps();

                $table->index(['snapshot_id', 'mac_address'], 'bridge_fdb_snapshot_mac_index');
                $table->index(['snapshot_id', 'vlan_id'], 'bridge_fdb_snapshot_vlan_index');
                $table->index(['snapshot_id', 'if_index'], 'bridge_fdb_snapshot_ifindex_index');
                $table->index(['snapshot_id', 'entry_status'], 'bridge_fdb_snapshot_entry_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_bridge_fdb_entries');
        Schema::dropIfExists('network_bridge_fdb_snapshots');
    }
};
