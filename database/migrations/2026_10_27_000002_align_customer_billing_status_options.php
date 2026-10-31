<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('company_service_options')) {
            return;
        }

        $now = now();

        // Keep the stable "left" code while matching the requested display label.
        DB::table('company_service_options')
            ->where('category', 'billing_status')
            ->where('code', 'left')
            ->update(['name' => 'Left', 'is_active' => true, 'updated_at' => $now]);

        foreach ([
            'free' => ['name' => 'Free', 'description' => 'Free customer billing status.'],
            'personal' => ['name' => 'Personal', 'description' => 'Personal customer billing status.'],
        ] as $code => $details) {
            $exists = DB::table('company_service_options')
                ->where('category', 'billing_status')
                ->where('code', $code)
                ->exists();

            if ($exists) {
                DB::table('company_service_options')
                    ->where('category', 'billing_status')
                    ->where('code', $code)
                    ->update([
                        'name' => $details['name'],
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            DB::table('company_service_options')->insert([
                'category' => 'billing_status',
                'code' => $code,
                'name' => $details['name'],
                'description' => $details['description'],
                'is_active' => true,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Retain service status options across rollback to protect existing records.
    }
};
