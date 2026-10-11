<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bom_id')->constrained()->restrictOnDelete();
            // Warehouse the components come out of and the finished batch goes into.
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            $table->unsignedInteger('runs')->default(1);
            $table->unsignedInteger('output_qty_cartons');
            $table->date('production_date');
            // Finished batch details.
            $table->string('batch_no');
            $table->date('mfg_date')->nullable();
            $table->date('exp_date');
            $table->decimal('conversion_cost', 14, 2)->default(0);
            // Fixed on completion.
            $table->decimal('material_cost', 14, 2)->default(0);
            $table->decimal('cost_per_carton', 10, 2)->default(0);
            $table->foreignUlid('output_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('production_date');
        });

        Schema::create('production_consumptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_no');
            $table->unsignedInteger('qty_cartons');
            $table->decimal('cost_per_carton', 10, 2);
            $table->decimal('line_value', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_consumptions');
        Schema::dropIfExists('production_orders');
    }
};
