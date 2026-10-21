<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Templates ("asset models"): how a kind of asset is booked and depreciated.
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('asset_account_code', 10)->default('1510');
            $table->string('accumulated_account_code', 10)->default('1590');
            $table->string('expense_account_code', 10)->default('6500');
            $table->enum('method', ['straight_line', 'declining_balance', 'declining_then_straight'])->default('straight_line');
            $table->unsignedSmallInteger('duration_periods')->default(60);
            $table->unsignedTinyInteger('period_months')->default(1);
            $table->decimal('declining_factor', 6, 4)->default(0.3);
            $table->boolean('prorata')->default(false);
            $table->decimal('salvage_pct', 5, 2)->default(0);
            $table->boolean('auto_post')->default(true);
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('name');
            $table->foreignUlid('category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->enum('status', ['draft', 'running', 'paused', 'fully_depreciated', 'sold', 'disposed', 'cancelled'])->default('draft');

            $table->date('acquisition_date');
            $table->date('depreciation_start_date');
            $table->decimal('original_value', 14, 2);
            $table->decimal('salvage_value', 14, 2)->default(0);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->enum('method', ['straight_line', 'declining_balance', 'declining_then_straight'])->default('straight_line');
            $table->unsignedSmallInteger('duration_periods');
            $table->unsignedTinyInteger('period_months')->default(1);
            $table->decimal('declining_factor', 6, 4)->default(0.3);
            $table->boolean('prorata')->default(false);
            $table->boolean('auto_post')->default(true);

            $table->string('asset_account_code', 10);
            $table->string('accumulated_account_code', 10);
            $table->string('expense_account_code', 10);

            $table->ulid('supplier_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->foreignUlid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->foreignUlid('acquisition_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_value', 14, 2)->nullable();
            $table->string('disposal_reason')->nullable();
            $table->foreignUlid('disposal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('period_start');
            $table->date('depreciation_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('accumulated_after', 14, 2);
            $table->decimal('book_value_after', 14, 2);
            $table->enum('status', ['planned', 'posted'])->default('planned');
            $table->foreignUlid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUlid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'sequence']);
            $table->index(['tenant_id', 'status', 'depreciation_date'], 'asset_depr_tenant_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
