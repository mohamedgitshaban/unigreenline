<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a product is planned. A product with no row is planned as "exact demand".
        Schema::create('product_planning', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            // null = automatic: made when it has an approved BoM, otherwise bought.
            $table->enum('procurement', ['make', 'buy'])->nullable();
            // Days from placing the order to having the stock.
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->enum('lot_rule', ['exact', 'fixed', 'min_max', 'multiple'])->default('exact');
            $table->unsignedInteger('lot_qty')->nullable();
            $table->unsignedInteger('min_qty')->nullable();
            $table->unsignedInteger('max_qty')->nullable();
            $table->unsignedInteger('multiple_qty')->nullable();
            // Made only against customer demand, never to stock.
            $table->boolean('make_to_order')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
        });

        // Planned requirements that are not sales orders: a manual need or a forecast.
        Schema::create('mrp_demands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_cartons');
            $table->date('due_date');
            $table->enum('kind', ['manual', 'forecast'])->default('manual');
            $table->enum('status', ['open', 'fulfilled', 'cancelled'])->default('open');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('mrp_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            // What was asked for: warehouse, horizon, product filter, ...
            $table->json('inputs')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        // The requirements report of a run: one row per product and warehouse.
        Schema::create('mrp_run_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('mrp_run_id')->constrained('mrp_runs')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level')->default(0);
            $table->decimal('gross_requirement', 14, 4)->default(0);
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->decimal('incoming', 14, 4)->default(0);
            $table->unsignedInteger('safety_stock')->default(0);
            $table->decimal('net_requirement', 14, 4)->default(0);
            $table->unsignedInteger('planned_qty')->default(0);
            $table->date('due_date')->nullable();
            $table->enum('action', ['none', 'manufacture', 'purchase'])->default('none');
            $table->json('sources')->nullable();
            $table->timestamps();

            $table->index(['mrp_run_id', 'product_id']);
        });

        Schema::create('mrp_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // The latest run that confirmed this suggestion.
            $table->foreignUlid('mrp_run_id')->nullable()->constrained('mrp_runs')->nullOnDelete();
            $table->enum('type', ['manufacture', 'purchase']);
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_cartons');
            $table->date('start_date');
            $table->date('due_date');
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->enum('status', ['proposed', 'converted', 'dismissed', 'superseded'])->default('proposed');
            // type + product + warehouse + due date; stays identical across re-runs.
            $table->string('fingerprint', 64);
            // Equal to the fingerprint while proposed, null afterwards: at most one open proposal per fingerprint.
            $table->string('open_key', 64)->nullable();
            $table->json('sources')->nullable();
            $table->string('converted_type', 40)->nullable();
            $table->string('converted_id', 40)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'open_key']);
            $table->index(['tenant_id', 'status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_suggestions');
        Schema::dropIfExists('mrp_run_lines');
        Schema::dropIfExists('mrp_runs');
        Schema::dropIfExists('mrp_demands');
        Schema::dropIfExists('product_planning');
    }
};
