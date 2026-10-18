<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock set aside for a document (a manufacturing order, ...) without
        // moving it. Every flow that takes stock out of a batch — sales
        // deduction, transfers, write-offs — may only take what is *not* reserved
        // by someone else, so two documents can never spend the same cartons.
        // Running total of a batch's active reservations, kept under the batch row
        // lock so every flow that locks the row sees the true figure.
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->unsignedInteger('reserved_cartons')->default(0)->after('qty_packs');
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            // The reserved batch. Nulled if the (emptied) batch row is ever removed.
            $table->foreignUlid('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->unsignedInteger('qty_cartons');
            $table->enum('status', ['active', 'consumed', 'released'])->default('active');
            // What holds the reservation, e.g. ('production_order', <id>).
            $table->string('source_type', 40);
            $table->string('source_id', 40);
            // Optional finer grain, e.g. the manufacturing-order component line.
            $table->string('source_line_id', 40)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index(['product_id', 'warehouse_id', 'status']);
            $table->index(['source_type', 'source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('reserved_cartons');
        });
    }
};
