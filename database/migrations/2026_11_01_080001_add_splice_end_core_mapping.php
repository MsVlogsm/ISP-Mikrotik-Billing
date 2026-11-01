<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('network_topology_links', function (Blueprint $t) {
  $t->unsignedInteger('source_core')->nullable()->after('fiber_core');
  $t->string('source_core_color',32)->nullable()->after('source_core');
  $t->string('source_core_hex',7)->nullable()->after('source_core_color');
  $t->unsignedInteger('target_core')->nullable()->after('source_core_hex');
  $t->string('target_core_color',32)->nullable()->after('target_core');
  $t->string('target_core_hex',7)->nullable()->after('target_core_color');
 }); }
 public function down(): void { Schema::table('network_topology_links', fn(Blueprint $t) => $t->dropColumn(['source_core','source_core_color','source_core_hex','target_core','target_core_color','target_core_hex'])); }
};
