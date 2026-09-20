<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('city');
            $table->string('governorate');
            $table->string('address')->nullable();
            $table->foreignUlid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->string('temperature')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('phone')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->decimal('stock_value', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
