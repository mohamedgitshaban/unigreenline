<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sales_rep_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'picking', 'invoiced', 'delivered', 'cancelled'])->default('draft');
            $table->enum('pay_type', ['cash', 'credit']);
            $table->unsignedSmallInteger('grace_period')->default(0);
            $table->date('due_date')->nullable();
            $table->decimal('invoice_discount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('stock_deducted')->default(false);
            $table->text('notes')->nullable();
            $table->date('order_date');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('sales_rep_id');
            $table->index('status');
            $table->index('order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
