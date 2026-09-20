<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('batch_no');
            $table->date('mfg_date')->nullable();
            $table->date('exp_date');
            $table->date('rcv_date');
            $table->unsignedInteger('qty_cartons')->default(0);
            $table->unsignedInteger('qty_packs')->default(0);
            $table->decimal('cost_per_carton', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_no', 'warehouse_id']);
            // The FEFO query path: earliest-expiring, earliest-received batch
            // for a product in a specific warehouse. Must stay fast.
            $table->index(['product_id', 'warehouse_id', 'exp_date', 'rcv_date'], 'inventory_batches_fefo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
