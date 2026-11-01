<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['network_topology_nodes', 'network_topology_links'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('verification_status', 24)->default('pending')->index();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            });
            // Preserve the existing live topology; only already-published records
            // are grandfathered as verified. Drafts remain pending.
            DB::table($tableName)->where('is_published', true)->update([
                'verification_status' => 'verified', 'verified_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['network_topology_nodes', 'network_topology_links'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('verified_by');
                $table->dropIndex([$tableName === 'network_topology_nodes' ? 'verification_status' : 'verification_status']);
                $table->dropColumn(['verification_status', 'verified_at']);
            });
        }
    }
};
