<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_positions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignUlid('recruiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('vacancies')->default(1);
            $table->unsignedSmallInteger('hired_count')->default(0);
            $table->enum('status', ['draft', 'open', 'closed'])->default('open');
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern'])->default('full_time');
            $table->string('location')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->date('deadline')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_recruitment_stages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(10);
            // The stage an applicant reaches when the contract is signed.
            $table->boolean('is_hired')->default(false);
            $table->text('requirements')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_refuse_reasons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_applicants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->foreignUlid('job_position_id')->nullable()->constrained('hr_job_positions')->nullOnDelete();
            $table->foreignUlid('stage_id')->constrained('hr_recruitment_stages')->restrictOnDelete();
            $table->string('source', 100)->nullable();
            $table->decimal('expected_salary', 12, 2)->nullable();
            $table->decimal('proposed_salary', 12, 2)->nullable();
            $table->date('availability_date')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->enum('kanban_state', ['normal', 'blocked', 'done'])->default('normal');
            $table->foreignUlid('recruiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'hired', 'refused', 'archived'])->default('active');
            $table->foreignUlid('refuse_reason_id')->nullable()->constrained('hr_refuse_reasons')->nullOnDelete();
            $table->timestamp('refused_at')->nullable();
            $table->foreignUlid('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->timestamp('hired_at')->nullable();
            $table->date('applied_on');
            $table->timestamp('stage_changed_at')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('resume_name')->nullable();
            $table->enum('offer_status', ['none', 'draft', 'sent', 'accepted', 'declined'])->default('none');
            $table->date('offer_valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'stage_id']);
            $table->index(['tenant_id', 'job_position_id']);
        });

        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
            $table->foreignUlid('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->enum('mode', ['onsite', 'phone', 'video'])->default('onsite');
            $table->string('location')->nullable();
            $table->enum('status', ['scheduled', 'done', 'cancelled'])->default('scheduled');
            $table->enum('result', ['pending', 'pass', 'fail'])->default('pending');
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'interviewer_id']);
        });

        Schema::create('hr_applicant_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('summary');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['applicant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_applicant_events');
        Schema::dropIfExists('hr_interviews');
        Schema::dropIfExists('hr_applicants');
        Schema::dropIfExists('hr_refuse_reasons');
        Schema::dropIfExists('hr_recruitment_stages');
        Schema::dropIfExists('hr_job_positions');
    }
};
