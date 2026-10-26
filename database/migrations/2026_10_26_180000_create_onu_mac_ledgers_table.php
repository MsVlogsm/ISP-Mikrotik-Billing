<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some existing X-Link installations already have this legacy table.
        // Keep their data and schema intact; create the tracked schema on fresh installs.
        if (Schema::hasTable('onu_mac_ledgers')) {
            return;
        }

        Schema::create('onu_mac_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('olt_device_id')->nullable()->constrained('network_inventory_devices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers_infos')->nullOnDelete();
            $table->string('customer_unique_id')->nullable()->index();
            $table->string('pon_port')->nullable();
            $table->string('onu_id')->nullable();
            $table->string('serial_no')->nullable()->index();
            $table->string('current_mac')->nullable()->index();
            $table->string('previous_mac')->nullable();
            $table->string('service_type')->nullable();
            $table->string('pppoe_username')->nullable()->index();
            $table->string('static_ip')->nullable();
            $table->unsignedInteger('vlan')->nullable();
            $table->decimal('rx_power', 7, 2)->nullable();
            $table->decimal('tx_power', 7, 2)->nullable();
            $table->string('status')->default('unknown')->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('mac_changed_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['olt_device_id', 'onu_id']);
        });
    }

    public function down(): void
    {
        // Intentionally retain the ledger on rollback: older deployments may have
        // created this table outside migrations, and it stores subscriber identity history.
    }
};
