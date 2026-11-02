<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('service_zones')) {
            Schema::create('service_zones', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                $table->string('code', 60)->nullable()->unique();
                $table->string('manager_name', 150)->nullable();
                $table->string('manager_phone', 40)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_sub_zones')) {
            Schema::create('service_sub_zones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_zone_id')->constrained('service_zones')->restrictOnDelete();
                $table->string('name', 150);
                $table->string('code', 60)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->unique(['service_zone_id', 'name']);
            });
        }

        if (! Schema::hasTable('distribution_boxes')) {
            Schema::create('distribution_boxes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_zone_id')->constrained('service_zones')->restrictOnDelete();
                $table->foreignId('service_sub_zone_id')->nullable()->constrained('service_sub_zones')->restrictOnDelete();
                $table->string('name', 150);
                $table->string('code', 60)->unique();
                $table->string('box_type', 20)->default('TJ');
                $table->unsignedSmallInteger('port_capacity')->default(0);
                $table->unsignedSmallInteger('ports_used')->default(0);
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->index(['service_zone_id', 'service_sub_zone_id']);
            });
        }

        if (! Schema::hasTable('service_districts')) {
            Schema::create('service_districts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                $table->string('code', 60)->nullable()->unique();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_upazilas')) {
            Schema::create('service_upazilas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_district_id')->nullable()->constrained('service_districts')->restrictOnDelete();
                $table->string('name', 150);
                $table->string('code', 60)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->unique(['service_district_id', 'name']);
            });
        }

        // Preserve current address dropdown values while migrating them into normalized,
        // editable service-area records. Existing customer/address values are not rewritten.
        if (Schema::hasTable('address_fields')) {
            $zoneField = DB::table('address_fields')->where('label', 'Zone')->first();
            foreach ((array) json_decode($zoneField?->dropdown_list ?: '[]', true) as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    DB::table('service_zones')->insertOrIgnore([
                        'name' => $name,
                        'code' => Str::slug($name) ?: null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $districtField = DB::table('address_fields')->where('label', 'District')->first();
            foreach ((array) json_decode($districtField?->dropdown_list ?: '[]', true) as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    DB::table('service_districts')->insertOrIgnore([
                        'name' => $name,
                        'code' => Str::slug($name) ?: null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $upazilaField = DB::table('address_fields')->where('label', 'Thana / Upazila')->first();
            foreach ((array) json_decode($upazilaField?->dropdown_list ?: '[]', true) as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    DB::table('service_upazilas')->insertOrIgnore([
                        'service_district_id' => null,
                        'name' => $name,
                        'code' => Str::slug($name) ?: null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_upazilas');
        Schema::dropIfExists('service_districts');
        Schema::dropIfExists('distribution_boxes');
        Schema::dropIfExists('service_sub_zones');
        Schema::dropIfExists('service_zones');
    }
};
