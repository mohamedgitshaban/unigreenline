<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_visits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('visit_date');
            $table->string('type')->nullable();
            $table->enum('outcome', ['positive', 'neutral', 'negative'])->nullable();
            $table->text('notes')->nullable();
            $table->date('next_visit')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'rep_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
    }
};
