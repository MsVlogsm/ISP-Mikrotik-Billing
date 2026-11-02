<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->unique()->constrained('package_lists')->cascadeOnDelete();
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->unique();
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_amount');
            $table->string('recurring_interval', 16);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stripe_customer_links', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->unique();
            $table->string('stripe_customer_id')->unique();
            $table->timestamps();
        });

        Schema::create('stripe_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->index();
            $table->string('stripe_customer_id')->index();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('checkout_idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('package_id')->nullable()->index();
            $table->string('stripe_price_id')->nullable();
            $table->string('status', 40)->default('checkout_starting')->index();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('unit_amount')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->string('last_invoice_id')->nullable()->index();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_unique_id', 'status']);
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('event_type', 120)->index();
            $table->string('object_id')->nullable()->index();
            $table->string('processing_status', 24)->default('received')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('stripe_subscriptions');
        Schema::dropIfExists('stripe_customer_links');
        Schema::dropIfExists('stripe_package_prices');
    }
};
