<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('license_plate', 30);
            $table->string('vin', 40)->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->enum('vehicle_type', ['car', 'van', 'truck', 'motorcycle', 'other'])->default('car');
            $table->enum('fuel_type', ['diesel', 'gasoline', 'electric', 'hybrid', 'cng', 'other'])->default('gasoline');
            $table->enum('transmission', ['manual', 'automatic'])->nullable();
            $table->string('color', 40)->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->decimal('odometer', 10, 1)->default(0);
            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_cost', 12, 2)->nullable();
            $table->decimal('residual_value', 12, 2)->nullable();
            $table->enum('status', ['active', 'in_maintenance', 'out_of_service', 'sold'])->default('active');
            $table->foreignUlid('driver_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('fixed_asset_id', 40)->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'license_plate']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_vehicle_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('hr_vehicles')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'starts_on']);
        });

        Schema::create('hr_vehicle_odometer_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('hr_vehicles')->cascadeOnDelete();
            $table->date('logged_on');
            $table->decimal('value', 10, 1);
            $table->foreignUlid('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'logged_on']);
        });

        Schema::create('hr_fleet_service_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('category', ['service', 'repair', 'inspection', 'other'])->default('service');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_vehicle_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('hr_vehicles')->cascadeOnDelete();
            $table->foreignUlid('service_type_id')->constrained('hr_fleet_service_types')->restrictOnDelete();
            $table->date('service_date');
            $table->enum('status', ['planned', 'done', 'cancelled'])->default('planned');
            $table->decimal('odometer', 10, 1)->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('supplier_id', 40)->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->date('next_due_date')->nullable();
            $table->decimal('next_due_odometer', 10, 1)->nullable();
            $table->date('last_reminded_on')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'service_date']);
            $table->index(['vehicle_id', 'service_date']);
        });

        Schema::create('hr_vehicle_fuel_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('hr_vehicles')->cascadeOnDelete();
            $table->date('fueled_on');
            $table->decimal('liters', 9, 2);
            $table->decimal('price_per_liter', 9, 3)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('odometer', 10, 1)->nullable();
            $table->boolean('full_tank')->default(true);
            $table->string('vendor_name')->nullable();
            $table->foreignUlid('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'fueled_on']);
            $table->index(['tenant_id', 'fueled_on']);
        });

        Schema::create('hr_vehicle_contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('hr_vehicles')->cascadeOnDelete();
            $table->enum('type', ['insurance', 'leasing', 'registration', 'other'])->default('insurance');
            $table->string('reference')->nullable();
            $table->string('supplier_id', 40)->nullable();
            $table->string('vendor_name')->nullable();
            $table->date('starts_on');
            $table->date('expires_on');
            $table->decimal('cost', 12, 2)->default(0);
            $table->enum('frequency', ['once', 'monthly', 'yearly'])->default('yearly');
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->unsignedSmallInteger('reminder_days')->default(30);
            $table->date('last_reminded_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_on']);
            $table->index(['vehicle_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_vehicle_contracts');
        Schema::dropIfExists('hr_vehicle_fuel_logs');
        Schema::dropIfExists('hr_vehicle_services');
        Schema::dropIfExists('hr_fleet_service_types');
        Schema::dropIfExists('hr_vehicle_odometer_logs');
        Schema::dropIfExists('hr_vehicle_assignments');
        Schema::dropIfExists('hr_vehicles');
    }
};
