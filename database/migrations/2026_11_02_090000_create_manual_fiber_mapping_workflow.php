<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiber_cables', function (Blueprint $t) {
            $t->id(); $t->string('cable_id',80)->unique(); $t->string('route_name',160);
            $t->string('start_location',190)->nullable(); $t->string('end_location',190)->nullable();
            $t->decimal('start_latitude',10,7)->nullable(); $t->decimal('start_longitude',10,7)->nullable();
            $t->decimal('end_latitude',10,7)->nullable(); $t->decimal('end_longitude',10,7)->nullable();
            $t->unsignedSmallInteger('core_count'); $t->string('color_standard',60)->default('TIA-598');
            $t->decimal('length_m',12,2)->nullable(); $t->date('installed_at')->nullable(); $t->string('manufacturer',120)->nullable();
            $t->string('status',24)->default('pending'); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
        });
        Schema::create('fiber_olt_pon_mappings', function (Blueprint $t) {
            $t->id(); $t->foreignId('olt_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
            $t->string('pon_slot',40); $t->string('pon_port',40); $t->string('port_description',190)->nullable();
            $t->foreignId('uplink_cable_id')->nullable()->constrained('fiber_cables')->nullOnDelete();
            $t->string('status',24)->default('pending'); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique(['olt_device_id','pon_slot','pon_port'],'fiber_olt_pon_unique');
        });
        Schema::create('fiber_splice_closures', function (Blueprint $t) {
            $t->id(); $t->string('closure_id',80)->unique(); $t->string('location',190)->nullable();
            $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
            $t->string('tray_reference',80)->nullable(); $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
        });
        Schema::create('fiber_splice_records', function (Blueprint $t) {
            $t->id(); $t->foreignId('closure_id')->constrained('fiber_splice_closures')->cascadeOnDelete();
            $t->foreignId('source_cable_id')->constrained('fiber_cables')->restrictOnDelete(); $t->unsignedSmallInteger('source_core');
            $t->foreignId('target_cable_id')->constrained('fiber_cables')->restrictOnDelete(); $t->unsignedSmallInteger('target_core');
            $t->string('tray_port',80)->nullable(); $t->string('status',24)->default('pending');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique(['closure_id','source_cable_id','source_core','target_cable_id','target_core'],'fiber_splice_exact_unique');
        });
        Schema::create('fiber_splitters', function (Blueprint $t) {
            $t->id(); $t->string('splitter_id',80)->unique(); $t->string('location',190)->nullable();
            $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
            $t->string('ratio',12); $t->foreignId('input_cable_id')->nullable()->constrained('fiber_cables')->nullOnDelete();
            $t->unsignedSmallInteger('input_core')->nullable(); $t->string('input_port',40)->nullable();
            $t->string('status',24)->default('pending'); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
        });
        Schema::create('fiber_splitter_ports', function (Blueprint $t) {
            $t->id(); $t->foreignId('splitter_id')->constrained('fiber_splitters')->cascadeOnDelete();
            $t->unsignedSmallInteger('output_port'); $t->foreignId('next_splitter_id')->nullable()->constrained('fiber_splitters')->nullOnDelete();
            $t->foreignId('output_cable_id')->nullable()->constrained('fiber_cables')->nullOnDelete(); $t->unsignedSmallInteger('output_core')->nullable();
            $t->string('status',24)->default('pending'); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique(['splitter_id','output_port'],'fiber_splitter_port_unique');
        });
        Schema::create('fiber_customer_locations', function (Blueprint $t) {
            $t->id(); $t->foreignId('customer_id')->constrained('customers_infos')->cascadeOnDelete();
            $t->decimal('latitude',10,7); $t->decimal('longitude',10,7); $t->string('location_note',190)->nullable();
            $t->string('status',24)->default('pending'); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique('customer_id');
        });
        Schema::create('fiber_customer_mappings', function (Blueprint $t) {
            $t->id(); $t->foreignId('onu_mapping_id')->nullable()->constrained('olt_onu_customer_mappings')->nullOnDelete();
            $t->foreignId('customer_id')->constrained('customers_infos')->cascadeOnDelete();
            $t->foreignId('splitter_port_id')->constrained('fiber_splitter_ports')->restrictOnDelete();
            $t->string('onu_mac',40)->nullable()->index(); $t->string('onu_serial',100)->nullable()->index();
            $t->string('pppoe_user_id',120)->nullable(); $t->string('status',24)->default('pending');
            $t->text('verification_note')->nullable(); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique('splitter_port_id','fiber_customer_port_unique');
        });
        Schema::create('fiber_mapping_audits', function (Blueprint $t) {
            $t->id(); $t->string('entity_type',60); $t->unsignedBigInteger('entity_id'); $t->string('action',40);
            $t->json('before_data')->nullable(); $t->json('after_data')->nullable(); $t->text('note')->nullable();
            $t->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
            $t->index(['entity_type','entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_mapping_audits'); Schema::dropIfExists('fiber_customer_mappings');
        Schema::dropIfExists('fiber_customer_locations'); Schema::dropIfExists('fiber_splitter_ports');
        Schema::dropIfExists('fiber_splitters'); Schema::dropIfExists('fiber_splice_records');
        Schema::dropIfExists('fiber_splice_closures'); Schema::dropIfExists('fiber_olt_pon_mappings'); Schema::dropIfExists('fiber_cables');
    }
};
