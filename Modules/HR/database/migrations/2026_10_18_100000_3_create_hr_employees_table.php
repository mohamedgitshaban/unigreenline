<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // The login this employee uses to check in and see their own records.
            $table->foreignUlid('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignUlid('work_schedule_id')->nullable()->constrained('hr_work_schedules')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('national_id', 50)->nullable();
            $table->string('job_title')->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('basic_salary', 12, 2);
            // Fixed monthly allowances (housing, transport...), paid on top of the basic salary.
            $table->decimal('allowances', 12, 2)->default(0);
            // Employee's share of social insurance, as a percentage of the basic salary.
            $table->decimal('insurance_pct', 5, 2)->default(0);
            // Income tax withheld, as a percentage of the pay earned after insurance.
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->boolean('overtime_eligible')->default(false);
            $table->unsignedSmallInteger('annual_leave_days')->default(21);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};
