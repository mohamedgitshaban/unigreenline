<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unit names an admin maintains (Packet, Package, Carton, ...).
        Schema::create('units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // A product's packaging ladder, smallest unit first: e.g. Packet (1),
        // Package (contains 10 packets = 10), Carton (contains 3 packages = 30).
        // base_qty is always counted in the smallest unit; stock is stored in
        // the largest one.
        Schema::create('product_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unsignedInteger('contains')->nullable();
            $table->unsignedInteger('base_qty');
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->unique(['product_id', 'position']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Per smallest unit, like pack_selling_price. Null = no floor.
            $table->decimal('min_selling_price', 10, 2)->nullable()->after('pack_selling_price');
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->boolean('is_free_gift')->default(false)->after('cost_per_carton');
            $table->ulid('source_po_id')->nullable()->after('is_free_gift');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn(['is_free_gift', 'source_po_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_selling_price');
        });
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('units');
    }
};
