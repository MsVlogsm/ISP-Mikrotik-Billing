<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('package_lists') && ! Schema::hasColumn('package_lists', 'bandwidth_type')) {
            Schema::table('package_lists', function (Blueprint $table) {
                $table->string('bandwidth_type', 80)->nullable()->after('speed')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('package_lists') && Schema::hasColumn('package_lists', 'bandwidth_type')) {
            Schema::table('package_lists', function (Blueprint $table) {
                $table->dropIndex(['bandwidth_type']);
                $table->dropColumn('bandwidth_type');
            });
        }
    }
};
