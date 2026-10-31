<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('client_id', 80)->unique();
            $table->string('client_secret_hash', 64);
            $table->json('scopes');
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('integration_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->longText('secret_encrypted');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('integration_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('integration_webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 100);
            $table->json('payload');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'created_at'], 'webhook_delivery_endpoint_created_idx');
            $table->index(['status', 'created_at'], 'webhook_delivery_status_created_idx');
        });

        Schema::create('zabbix_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('api_url', 2048);
            $table->longText('api_token_encrypted');
            $table->boolean('is_active')->default(true);
            $table->string('api_version', 40)->nullable();
            $table->unsignedInteger('host_count')->nullable();
            $table->unsignedInteger('problem_count')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zabbix_servers');
        Schema::dropIfExists('integration_webhook_deliveries');
        Schema::dropIfExists('integration_webhook_endpoints');
        Schema::dropIfExists('integration_api_clients');
    }
};
