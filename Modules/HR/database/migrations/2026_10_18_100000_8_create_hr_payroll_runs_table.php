<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->unsignedInteger('employees_count')->default(0);
            // Pay earned after attendance deductions — the salaries expense posted on approval.
            $table->decimal('total_earned', 14, 2)->default(0);
            // Insurance and tax withheld — owed to the authorities, posted to Payroll Taxes & Insurance Payable.
            $table->decimal('total_withheld', 14, 2)->default(0);
            // What employees actually receive — posted to Salaries Payable, cleared on payment.
            $table->decimal('total_net', 14, 2)->default(0);
            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->enum('payment_method', ['cash', 'bank'])->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_runs');
    }
};
