<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // The supplier's own invoice, recorded when the PO is received.
            $table->string('supplier_invoice_no')->nullable()->after('notes');
            $table->date('supplier_invoice_date')->nullable()->after('supplier_invoice_no');
            $table->decimal('supplier_invoice_amount', 14, 2)->nullable()->after('supplier_invoice_date');
            $table->string('supplier_invoice_path')->nullable()->after('supplier_invoice_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['supplier_invoice_no', 'supplier_invoice_date', 'supplier_invoice_amount', 'supplier_invoice_path']);
        });
    }
};
