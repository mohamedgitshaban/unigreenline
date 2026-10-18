<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('employee_code');
            $table->string('employee_name');

            // The month, as this employee lived it.
            $table->unsignedSmallInteger('period_days')->default(0);
            $table->unsignedSmallInteger('working_days')->default(0);
            $table->unsignedSmallInteger('weekend_days')->default(0);
            $table->unsignedSmallInteger('holiday_days')->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('paid_leave_days')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->unsignedSmallInteger('incomplete_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('shortfall_minutes')->default(0);
            $table->unsignedInteger('excused_minutes')->default(0);
            $table->unsignedInteger('unexcused_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            // The money.
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('gross_earnings', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('shortfall_deduction', 12, 2)->default(0);
            $table->decimal('attendance_deductions', 12, 2)->default(0);
            $table->decimal('earned_pay', 12, 2)->default(0);
            $table->decimal('insurance', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
