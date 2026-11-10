<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // The date the customer asked for the goods; the delivery row keeps the actual date.
            $table->date('delivery_date')->nullable()->after('order_date');
            $table->decimal('shipping_cost', 14, 2)->default(0)->after('invoice_discount');
            // Whether the shipping is added to the total (charged to the customer). Fixed when the order is created.
            $table->boolean('shipping_charged')->default(false)->after('shipping_cost');
            $table->decimal('invoice_discount_pct', 5, 2)->nullable()->after('invoice_discount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('shipping_amount', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('returned_total', 14, 2)->default(0)->after('paid');
        });

        Schema::table('returns', function (Blueprint $table) {
            // The invoice line a return was made against, when it was made from the invoice.
            $table->foreignUlid('so_line_id')->nullable()->after('invoice_id')->constrained('sales_order_lines')->nullOnDelete();
            // The VAT credited back, for returns made from an invoice (`amount` stays net of tax).
            $table->decimal('tax_amount', 14, 2)->default(0)->after('amount');
        });

        DB::statement("ALTER TABLE deliveries MODIFY status ENUM('pending', 'preparing', 'out_for_delivery', 'delivered', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::table('deliveries')->where('status', 'cancelled')->update(['status' => 'failed']);
        DB::statement("ALTER TABLE deliveries MODIFY status ENUM('pending', 'preparing', 'out_for_delivery', 'delivered', 'failed') NOT NULL DEFAULT 'pending'");

        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('so_line_id');
            $table->dropColumn('tax_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['shipping_amount', 'returned_total']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_date', 'shipping_cost', 'shipping_charged', 'invoice_discount_pct']);
        });
    }
};
