<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_bridge_host_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('router_list_id')->constrained('router_lists')->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->string('source', 24)->default('routeros-api');
            $table->string('status', 24)->default('unknown');
            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedInteger('dynamic_count')->default(0);
            $table->unsignedInteger('local_count')->default(0);
            $table->unsignedInteger('external_count')->default(0);
            $table->unsignedInteger('disabled_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('poll_duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['router_list_id', 'sampled_at'], 'mtk_bridge_host_router_sampled_idx');
            $table->index(['router_list_id', 'status'], 'mtk_bridge_host_router_status_idx');
        });

        Schema::create('mikrotik_bridge_host_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('mikrotik_bridge_host_snapshots')->cascadeOnDelete();
            $table->string('router_entry_id', 64)->nullable();
            $table->string('mac_address', 17);
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->string('bridge_name', 160)->nullable();
            $table->string('interface_name', 160)->nullable();
            $table->boolean('is_dynamic')->default(false);
            $table->boolean('is_local')->default(false);
            $table->boolean('is_external')->default(false);
            $table->boolean('is_disabled')->default(false);
            $table->boolean('is_invalid')->default(false);
            $table->timestamps();

            $table->index(['snapshot_id', 'mac_address'], 'mtk_bridge_host_snapshot_mac_idx');
            $table->index(['snapshot_id', 'vlan_id'], 'mtk_bridge_host_snapshot_vlan_idx');
            $table->index(['snapshot_id', 'interface_name'], 'mtk_bridge_host_snapshot_if_idx');
        });

        Schema::create('mikrotik_bridge_host_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('router_list_id')->constrained('router_lists')->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('mikrotik_bridge_host_snapshots')->cascadeOnDelete();
            $table->foreignId('previous_snapshot_id')->nullable()->constrained('mikrotik_bridge_host_snapshots')->nullOnDelete();
            $table->string('mac_address', 17);
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->string('from_bridge', 160)->nullable();
            $table->string('to_bridge', 160)->nullable();
            $table->string('from_interface', 160)->nullable();
            $table->string('to_interface', 160)->nullable();
            $table->timestamp('moved_at');
            $table->unsignedSmallInteger('moves_in_10m')->default(1);
            $table->boolean('is_flapping')->default(false);
            $table->timestamps();

            $table->index(['router_list_id', 'moved_at'], 'mtk_bridge_move_router_time_idx');
            $table->index(['router_list_id', 'mac_address', 'moved_at'], 'mtk_bridge_move_router_mac_idx');
            $table->index(['is_flapping', 'moved_at'], 'mtk_bridge_move_flapping_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_bridge_host_movements');
        Schema::dropIfExists('mikrotik_bridge_host_entries');
        Schema::dropIfExists('mikrotik_bridge_host_snapshots');
    }
};
