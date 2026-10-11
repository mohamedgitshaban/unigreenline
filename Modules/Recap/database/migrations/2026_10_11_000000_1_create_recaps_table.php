<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recaps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('reason', ['expired', 'damaged', 'lost', 'recall', 'other'])->default('expired');
            $table->enum('status', ['draft', 'approved', 'rejected'])->default('draft');
            $table->date('recap_date');
            $table->text('notes')->nullable();
            // Cost of the written-off stock; fixed when the recap is approved.
            $table->decimal('total_value', 14, 2)->default(0);
            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('recap_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recaps');
    }
};
