<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olt_onu_customer_ppp_links')) {
            Schema::create('olt_onu_customer_ppp_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('olt_onu_customer_mapping_id')
                    ->constrained('olt_onu_customer_mappings')
                    ->cascadeOnDelete();
                $table->foreignId('customer_id')
                    ->constrained('customers_infos')
                    ->cascadeOnDelete();
                $table->foreignId('ppp_user_id')
                    ->constrained('p_p_p_secrets')
                    ->cascadeOnDelete();
                $table->string('client_mac', 17)->nullable();
                $table->string('source', 64)->default('legacy_primary');
                $table->string('status', 24)->default('active');
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(
                    ['olt_onu_customer_mapping_id', 'ppp_user_id'],
                    'onu_customer_ppp_link_mapping_user_unique'
                );
                $table->index(['ppp_user_id', 'status'], 'onu_customer_ppp_link_user_status_index');
                $table->index(['customer_id', 'status'], 'onu_customer_ppp_link_customer_status_index');
            });
        }

        // Backfill the old one-customer primary mapping into the new link table.
        // Legacy columns remain untouched for existing application code.
        $mappings = DB::table('olt_onu_customer_mappings as m')
            ->join('customers_infos as c', 'c.id', '=', 'm.customer_id')
            ->whereNotNull('m.customer_id')
            ->whereNotNull('c.ppp_user_id')
            ->where(function ($query): void {
                $query->whereNull('m.ppp_user_id')
                    ->orWhereColumn('m.ppp_user_id', 'c.ppp_user_id');
            })
            ->select([
                'm.id as mapping_id',
                'c.id as customer_id',
                'c.ppp_user_id',
                'c.status as customer_status',
                'm.created_at',
                'm.last_seen_at',
            ])
            ->get();

        foreach ($mappings as $mapping) {
            $ppp = DB::table('p_p_p_secrets')->where('id', $mapping->ppp_user_id)->first(['id', 'status']);
            if (! $ppp) {
                continue;
            }

            $active = strtolower((string) $mapping->customer_status) === 'active'
                && strtolower((string) $ppp->status) === 'active';
            $now = now();

            DB::table('olt_onu_customer_ppp_links')->insertOrIgnore([
                'olt_onu_customer_mapping_id' => $mapping->mapping_id,
                'customer_id' => $mapping->customer_id,
                'ppp_user_id' => $mapping->ppp_user_id,
                'client_mac' => null,
                'source' => 'legacy_primary',
                'status' => $active ? 'active' : 'inactive',
                'first_seen_at' => $mapping->created_at ?: $now,
                'last_seen_at' => $mapping->last_seen_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_onu_customer_ppp_links');
    }
};
