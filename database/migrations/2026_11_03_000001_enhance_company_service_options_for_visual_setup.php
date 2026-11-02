<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('company_service_options') && ! Schema::hasColumn('company_service_options', 'sort_order')) {
            Schema::table('company_service_options', function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_system');
            });
        }

        if (! Schema::hasTable('company_service_options')) {
            return;
        }

        $now = now();
        $presets = [
            'protocol' => [
                ['ipoe', 'IPoE', 'IP over Ethernet with DHCP Option 82 subscriber identification.', 6],
            ],
            'customer_type' => [
                ['soho', 'SOHO', 'Small Office / Home Office subscriber.', 6],
                ['free_staff', 'Free / Staff', 'Internal employee or complimentary connection.', 7],
            ],
            'billing_status' => [
                ['suspended', 'Suspended', 'Service temporarily disabled due to non-payment, maintenance, or client request.', 8],
            ],
            'bandwidth_type' => [
                ['bdix', 'BDIX', 'Bangladesh Internet Exchange peering bandwidth.', 3],
                ['duplex', 'Duplex', 'Symmetric upload and download bandwidth.', 4],
                ['real_ip', 'Real IP', 'Public IPv4 routed addressing assignment.', 5],
            ],
        ];

        foreach ($presets as $category => $items) {
            foreach ($items as [$code, $name, $description, $sortOrder]) {
                DB::table('company_service_options')->insertOrIgnore([
                    'category' => $category,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => true,
                    'is_system' => true,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $bandwidthOrder = [
            'dedicated' => 1,
            'shared' => 2,
            'bdix' => 3,
            'duplex' => 4,
            'real_ip' => 5,
        ];
        foreach ($bandwidthOrder as $code => $sortOrder) {
            DB::table('company_service_options')
                ->where('category', 'bandwidth_type')
                ->where('code', $code)
                ->update(['sort_order' => $sortOrder, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_service_options') && Schema::hasColumn('company_service_options', 'sort_order')) {
            Schema::table('company_service_options', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
