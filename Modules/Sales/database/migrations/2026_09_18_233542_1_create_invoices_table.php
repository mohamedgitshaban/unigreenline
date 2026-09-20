<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('so_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->date('issued_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('total', 14, 2);
            $table->decimal('paid', 14, 2)->default(0);
            $table->decimal('balance', 14, 2);
            $table->enum('status', ['outstanding', 'partial', 'paid', 'overdue', 'void'])->default('outstanding');
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
