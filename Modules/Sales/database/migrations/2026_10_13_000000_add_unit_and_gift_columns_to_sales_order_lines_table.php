<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            // The packaging unit the line was ordered in, and how many
            // smallest units it holds (null on lines ordered as plain Carton/Pack).
            $table->foreignUlid('unit_id')->nullable()->after('unit')->constrained('units')->nullOnDelete();
            $table->unsignedInteger('base_qty')->nullable()->after('unit_id');
            // A free-gift line is sold at 0 and draws from free-gift stock only.
            $table->boolean('is_gift')->default(false)->after('free_qty');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['base_qty', 'is_gift']);
        });
    }
};
