<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('routing_operation_id')->nullable()->constrained('routing_operations')->nullOnDelete();
            $table->string('number', 40);
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->foreignUlid('work_center_id')->constrained()->restrictOnDelete();
            // The work order that must be completed before this one can start.
            $table->foreignUlid('depends_on_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->enum('status', ['pending', 'ready', 'in_progress', 'paused', 'completed', 'cancelled'])->default('pending');

            $table->unsignedInteger('qty_planned');
            $table->unsignedInteger('qty_processed')->default(0);
            $table->unsignedInteger('qty_rejected')->default(0);

            $table->unsignedInteger('planned_minutes')->default(0);
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();

            $table->foreignUlid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();

            // Actual cost of the time spent, fixed when the work order completes.
            $table->decimal('labor_cost', 14, 2)->default(0);
            $table->decimal('machine_cost', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['work_center_id', 'scheduled_start', 'scheduled_end']);
            $table->index(['production_order_id', 'sequence']);
            $table->index('status');
        });

        // One row per stretch of actual work, so pausing and resuming adds up honestly.
        Schema::create('work_order_time_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->timestamps();

            $table->index(['work_order_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_time_logs');
        Schema::dropIfExists('work_orders');
    }
};
