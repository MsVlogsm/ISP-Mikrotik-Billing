<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('router_lists') && ! Schema::hasColumn('router_lists', 'notes')) {
            Schema::table('router_lists', function (Blueprint $table) { $table->string('notes', 1000)->nullable(); });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('router_lists') && Schema::hasColumn('router_lists', 'notes')) {
            Schema::table('router_lists', function (Blueprint $table) { $table->dropColumn('notes'); });
        }
    }
};
