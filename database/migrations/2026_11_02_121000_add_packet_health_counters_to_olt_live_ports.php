<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('olt_live_metric_ports', function (Blueprint $table) {
            $table->unsignedBigInteger('in_errors')->nullable()->after('out_octets');
            $table->unsignedBigInteger('out_errors')->nullable()->after('in_errors');
            $table->unsignedBigInteger('in_discards')->nullable()->after('out_errors');
            $table->unsignedBigInteger('out_discards')->nullable()->after('in_discards');
            $table->unsignedBigInteger('in_packets')->nullable()->after('out_discards');
            $table->unsignedBigInteger('out_packets')->nullable()->after('in_packets');
            $table->decimal('packet_health_percent', 7, 4)->nullable()->after('out_bps');
        });
    }

    public function down(): void
    {
        Schema::table('olt_live_metric_ports', function (Blueprint $table) {
            $table->dropColumn(['in_errors','out_errors','in_discards','out_discards','in_packets','out_packets','packet_health_percent']);
        });
    }
};
