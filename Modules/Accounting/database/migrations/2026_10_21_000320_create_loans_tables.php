<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('name');
            // borrowed: money we owe a lender; granted: money we lent to someone.
            $table->enum('kind', ['borrowed', 'granted'])->default('borrowed');
            $table->string('counterparty_type', 20)->nullable();
            $table->string('counterparty_id', 40)->nullable();
            $table->string('counterparty_name')->nullable();

            $table->decimal('principal', 14, 2);
            $table->decimal('annual_rate_pct', 7, 4)->default(0);
            $table->enum('method', ['annuity', 'fixed_principal', 'interest_only'])->default('annuity');
            $table->unsignedSmallInteger('periods');
            $table->unsignedTinyInteger('period_months')->default(1);
            $table->unsignedSmallInteger('grace_periods')->default(0);
            $table->date('start_date');
            $table->date('first_due_date');

            $table->string('disbursement_account_code', 10)->nullable();
            $table->string('principal_account_code', 10);
            $table->string('interest_account_code', 10);

            $table->enum('status', ['draft', 'active', 'closed', 'cancelled'])->default('draft');
            $table->decimal('outstanding_principal', 14, 2)->default(0);
            $table->decimal('interest_paid', 14, 2)->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('disbursement_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->date('last_reminded_on')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('opening_balance', 14, 2);
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('interest_amount', 14, 2);
            $table->decimal('payment_amount', 14, 2);
            $table->decimal('closing_balance', 14, 2);
            $table->enum('status', ['planned', 'paid'])->default('planned');
            $table->date('paid_on')->nullable();
            $table->string('payment_account_code', 10)->nullable();
            $table->foreignUlid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUlid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'sequence']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
    }
};
