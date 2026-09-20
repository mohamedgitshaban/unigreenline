<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('type', ['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other']);
            $table->enum('classification', ['A+', 'A', 'B', 'C'])->default('B');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('governorate')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->string('address')->nullable();
            $table->decimal('credit_limit', 14, 2)->default(50000);
            $table->string('pay_terms')->nullable();
            $table->decimal('balance', 14, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'sales_rep_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
