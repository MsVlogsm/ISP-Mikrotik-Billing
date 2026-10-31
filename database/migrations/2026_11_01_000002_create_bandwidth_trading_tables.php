<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('bandwidth_providers')) {
            Schema::create('bandwidth_providers', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name');
                $table->string('contact_name')->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('email')->nullable();
                $table->enum('status', ['active', 'disabled'])->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('bandwidth_transactions')) {
            Schema::create('bandwidth_transactions', function (Blueprint $table) {
                $table->id();
                $table->enum('direction', ['purchase', 'sale'])->index();
                $table->foreignId('provider_id')->nullable()->constrained('bandwidth_providers')->restrictOnDelete();
                $table->foreignId('bandwidth_reseller_id')->nullable()->constrained('bandwidth_resellers')->restrictOnDelete();
                $table->string('reference', 100)->nullable()->index();
                $table->decimal('bandwidth_mbps', 14, 3);
                $table->decimal('unit_rate', 14, 4)->default(0);
                $table->decimal('total_amount', 15, 2);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->date('transaction_date');
                $table->string('billing_period', 30)->nullable();
                $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['direction', 'transaction_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_transactions');
        Schema::dropIfExists('bandwidth_providers');
    }
};
