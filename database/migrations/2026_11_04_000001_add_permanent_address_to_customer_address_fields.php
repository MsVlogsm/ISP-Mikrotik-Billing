<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('address_fields')) {
            return;
        }

        // Add this standard optional field only when it does not already exist.
        // Existing administrator-defined settings are deliberately preserved.
        if (! DB::table('address_fields')->where('label', 'Permanent Address')->exists()) {
            $now = now();

            DB::table('address_fields')->insert([
                'label' => 'Permanent Address',
                'input_type' => 'textarea',
                'dropdown_list' => null,
                'required' => false,
                'print_preview' => false,
                'complain_preview' => false,
                'order' => 15,
                'receipt_order' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Keep the field if customer records already use it.
        if (
            Schema::hasTable('customers_addresses')
            && DB::table('customers_addresses')->where('label_name', 'Permanent Address')->exists()
        ) {
            return;
        }

        if (Schema::hasTable('address_fields')) {
            DB::table('address_fields')->where('label', 'Permanent Address')->delete();
        }
    }
};
