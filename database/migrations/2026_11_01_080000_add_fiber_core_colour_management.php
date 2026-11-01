<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('network_topology_nodes', function (Blueprint $t) { $t->unsignedInteger('fiber_core_count')->nullable()->after('port_capacity'); $t->string('fiber_color_standard',40)->default('tia_598')->after('fiber_core_count'); });
  Schema::table('network_topology_links', function (Blueprint $t) { $t->string('fiber_color_standard',40)->nullable()->after('fiber_type'); $t->string('fiber_core_color',32)->nullable()->after('fiber_color_standard'); $t->string('fiber_core_color_hex',7)->nullable()->after('fiber_core_color'); $t->unsignedInteger('fiber_tube_number')->nullable()->after('fiber_core_color_hex'); $t->string('fiber_tube_color',32)->nullable()->after('fiber_tube_number'); });
 }
 public function down(): void {
  Schema::table('network_topology_links', fn(Blueprint $t) => $t->dropColumn(['fiber_color_standard','fiber_core_color','fiber_core_color_hex','fiber_tube_number','fiber_tube_color']));
  Schema::table('network_topology_nodes', fn(Blueprint $t) => $t->dropColumn(['fiber_core_count','fiber_color_standard']));
 }
};
