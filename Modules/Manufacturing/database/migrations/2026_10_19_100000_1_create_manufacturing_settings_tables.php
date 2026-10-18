<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per company; created with these defaults the first time it is read.
        Schema::create('manufacturing_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Document numbering: {prefix}{zero-padded counter}, e.g. MO00042.
            $table->string('mo_prefix', 10)->default('MO');
            $table->string('bom_prefix', 10)->default('BOM');
            $table->string('work_order_prefix', 10)->default('WO');
            $table->string('scrap_prefix', 10)->default('SCR');
            $table->string('mrp_prefix', 10)->default('MRP');
            $table->unsignedTinyInteger('number_padding')->default(5);

            $table->foreignUlid('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->default(1);
            $table->unsignedSmallInteger('default_operation_minutes')->default(60);

            $table->enum('reservation_policy', ['on_confirm', 'manual'])->default('on_confirm');
            $table->enum('consumption_policy', ['backflush', 'manual'])->default('backflush');
            $table->enum('backorder_policy', ['ask', 'always', 'never'])->default('ask');
            $table->boolean('allow_partial_production')->default(true);
            $table->boolean('allow_over_consumption')->default(false);
            $table->boolean('scrap_requires_approval')->default(false);
            $table->enum('scrap_cost_policy', ['expense', 'absorb'])->default('expense');
            $table->boolean('require_bom_approval')->default(false);
            $table->boolean('auto_create_work_orders')->default(true);
            $table->boolean('allow_reopen_cancelled')->default(false);

            // Costing.
            $table->boolean('wip_accounting')->default(false);
            $table->decimal('overhead_rate_pct', 5, 2)->default(0);
            $table->enum('by_product_cost_method', ['none', 'fixed_pct', 'sales_value'])->default('fixed_pct');
            $table->unsignedTinyInteger('cost_decimals')->default(2);

            // Planning.
            $table->unsignedSmallInteger('mrp_horizon_days')->default(30);
            $table->boolean('cancel_unstarted_on_demand_cancel')->default(false);

            // Optional subset of the built-in order state machine; null = all of it.
            $table->json('allowed_transitions')->nullable();
            $table->timestamps();
        });

        Schema::create('manufacturing_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_sequences');
        Schema::dropIfExists('manufacturing_settings');
    }
};
