<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cost_price and selling_price from the spec (= pack price × carton_qty)
     * are NOT stored columns here — they're computed accessors on the Product
     * model instead. Storing them risked silent staleness if pack_cost_price,
     * pack_selling_price, or carton_qty changed after creation without every
     * caller remembering to recompute both derived columns.
     *
     * supplier_id has no foreign key yet — the suppliers table doesn't exist
     * until the Purchasing module. The constraint is added there.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->ulid('supplier_id')->nullable();
            $table->string('name');
            $table->string('sku');
            $table->string('brand')->nullable();
            $table->string('pack_unit');
            $table->unsignedInteger('carton_qty');
            $table->decimal('pack_cost_price', 10, 2);
            $table->decimal('pack_selling_price', 10, 2);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('tax_pct', 5, 2)->default(14);
            $table->unsignedInteger('min_stock_cartons')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
