<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->enum('type', ['Sales Return', 'Damaged', 'Expired Return', 'Wrong Item', 'Purchase Return']);
            $table->unsignedInteger('qty');
            $table->enum('unit', ['Carton', 'Pack']);
            $table->decimal('amount', 14, 2);
            $table->boolean('restocked')->default(false);
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('completed');
            $table->date('return_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
