<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p_p_p_secrets', function (Blueprint $table): void {
            if (! Schema::hasColumn('p_p_p_secrets', 'interface')) {
                $table->string('interface')->nullable();
            }
            if (! Schema::hasColumn('p_p_p_secrets', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
            if (! Schema::hasColumn('p_p_p_secrets', 'subnet_prefix')) {
                $table->string('subnet_prefix', 45)->nullable();
            }
            if (! Schema::hasColumn('p_p_p_secrets', 'gateway')) {
                $table->string('gateway', 45)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('p_p_p_secrets', function (Blueprint $table): void {
            foreach (['gateway', 'subnet_prefix', 'ip_address', 'interface'] as $column) {
                if (Schema::hasColumn('p_p_p_secrets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
