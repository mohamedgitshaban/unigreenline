<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recap_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recap_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            // Snapshots, so the document still reads correctly after the batch is consumed.
            $table->string('batch_no');
            $table->date('exp_date');
            $table->unsignedInteger('qty_cartons');
            $table->decimal('cost_per_carton', 10, 2);
            $table->decimal('line_value', 14, 2);
            $table->timestamps();

            $table->unique(['recap_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recap_lines');
    }
};
