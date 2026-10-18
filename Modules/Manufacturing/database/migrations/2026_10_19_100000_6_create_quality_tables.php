<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_check_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Where in the process it is carried out.
            $table->enum('point', ['incoming', 'in_process', 'final'])->default('final');
            $table->enum('type', ['pass_fail', 'measure'])->default('pass_fail');
            // Narrow it to a product and/or a routing operation; null = applies to any.
            $table->foreignUlid('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('routing_operation_id')->nullable()->constrained('routing_operations')->cascadeOnDelete();
            $table->decimal('min_value', 14, 4)->nullable();
            $table->decimal('max_value', 14, 4)->nullable();
            $table->string('unit_label', 30)->nullable();
            $table->boolean('mandatory')->default(true);
            $table->text('instructions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'point']);
        });

        Schema::create('quality_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('definition_id')->nullable()->constrained('quality_check_definitions')->nullOnDelete();
            // Copied from the definition so a later edit does not rewrite history.
            $table->string('name');
            $table->enum('point', ['incoming', 'in_process', 'final']);
            $table->enum('type', ['pass_fail', 'measure']);
            $table->decimal('min_value', 14, 4)->nullable();
            $table->decimal('max_value', 14, 4)->nullable();
            $table->boolean('mandatory')->default(true);

            $table->foreignUlid('production_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('work_order_id')->nullable()->constrained('work_orders')->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            // Anything else the check is about, e.g. ('purchase_order', <id>) for an incoming inspection.
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 40)->nullable();

            $table->enum('status', ['pending', 'passed', 'failed'])->default('pending');
            $table->decimal('measured_value', 14, 4)->nullable();
            $table->string('defect_class')->nullable();
            $table->unsignedInteger('rejected_cartons')->default(0);
            $table->text('notes')->nullable();
            $table->foreignUlid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'status']);
            $table->index(['work_order_id', 'status']);
        });

        Schema::create('quality_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('quality_check_id')->constrained('quality_checks')->cascadeOnDelete();
            $table->foreignUlid('production_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            $table->text('description');
            $table->text('corrective_action')->nullable();
            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_alerts');
        Schema::dropIfExists('quality_checks');
        Schema::dropIfExists('quality_check_definitions');
    }
};
