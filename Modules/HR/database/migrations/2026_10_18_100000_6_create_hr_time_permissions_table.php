<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Permissions" in the HR sense: an approved slip that excuses a stretch of the
        // working day (arriving late, leaving early, or being away for part of the day).
        Schema::create('hr_time_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->enum('type', ['late_arrival', 'early_leave', 'partial_absence']);
            $table->date('permission_date');
            $table->time('from_time');
            $table->time('to_time');
            $table->unsignedInteger('minutes');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'permission_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_time_permissions');
    }
};
