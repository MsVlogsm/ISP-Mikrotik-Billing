<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('network_inventory_devices', 'snmp_port')) {
            Schema::table('network_inventory_devices', function (Blueprint $table) {
                $table->unsignedInteger('snmp_port')->default(161);
            });
        }
    }

    public function down(): void
    {
        // Retain connection settings on rollback to avoid disrupting existing OLT records.
    }
};
