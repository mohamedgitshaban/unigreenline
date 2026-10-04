<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payee')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'approved', 'rejected'])->default('draft');
            $table->enum('payment_method', ['cash', 'bank']);
            $table->date('expense_date');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
