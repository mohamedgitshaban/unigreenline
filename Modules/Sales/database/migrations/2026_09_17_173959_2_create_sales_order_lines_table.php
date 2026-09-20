<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('so_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            // Informational only — actual FEFO deduction always ignores this
            // and picks batches by expiry order regardless (spec §4.7/§5.1).
            $table->string('batch_no')->nullable();
            $table->unsignedInteger('qty');
            $table->enum('unit', ['Carton', 'Pack']);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->unsignedInteger('free_qty')->default(0);
            $table->decimal('subtotal', 14, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
    }
};
