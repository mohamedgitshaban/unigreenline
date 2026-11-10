<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A warehouse asking for stock: from another warehouse (becomes a transfer), from Purchasing (becomes a draft
     * purchase order) or from Manufacturing (becomes a draft production order).
     */
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->nullable();
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('source', ['warehouse', 'purchasing', 'manufacturing']);
            $table->foreignUlid('from_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_cartons');
            // When the request was made in a packaging unit (6 packs), what was asked for; qty_cartons is that rounded up to whole cartons.
            $table->foreignUlid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->unsignedInteger('unit_qty')->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['requested', 'fulfilled', 'rejected', 'cancelled'])->default('requested');
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            // What the request turned into: a transfer, a purchase order or a production order.
            $table->string('result_type', 40)->nullable();
            $table->string('result_id', 40)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};
