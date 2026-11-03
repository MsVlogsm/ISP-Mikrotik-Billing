<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_summaries', function (Blueprint $table) {
            $table->uuid('payment_attempt_key')->nullable()->unique()->after('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('collection_summaries', function (Blueprint $table) {
            $table->dropUnique(['payment_attempt_key']);
            $table->dropColumn('payment_attempt_key');
        });
    }
};
