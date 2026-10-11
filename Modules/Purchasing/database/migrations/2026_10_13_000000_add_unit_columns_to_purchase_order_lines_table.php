<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            // What the buyer actually typed, when they ordered in a unit other
            // than cartons; qty_cartons / cost_per_carton are always the stock-unit equivalents.
            $table->foreignUlid('unit_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
            $table->unsignedInteger('unit_qty')->nullable()->after('unit_id');
            $table->decimal('cost_per_unit', 12, 4)->nullable()->after('unit_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['unit_qty', 'cost_per_unit']);
        });
    }
};
