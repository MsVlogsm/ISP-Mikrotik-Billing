<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 100)->unique();
            $table->string('label', 180);
            $table->string('cadence', 80)->nullable();
            $table->string('status', 20)->default('never');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedBigInteger('runs_count')->default(0);
            $table->unsignedBigInteger('failures_count')->default(0);
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->text('last_message')->nullable();
            $table->json('last_metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_started_at']);
            $table->index('last_success_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sync_statuses');
    }
};