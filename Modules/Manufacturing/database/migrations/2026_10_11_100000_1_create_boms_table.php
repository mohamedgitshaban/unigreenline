<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // The finished product this recipe makes.
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Cartons of the finished product one run of the recipe yields.
            $table->unsignedInteger('output_qty_cartons')->default(1);
            // Labour / overhead absorbed per run, on top of the materials.
            $table->decimal('conversion_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('bom_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('component_product_id')->constrained('products')->restrictOnDelete();
            // Cartons of the component one run consumes.
            $table->unsignedInteger('qty_cartons');
            $table->timestamps();

            $table->unique(['bom_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('boms');
    }
};
