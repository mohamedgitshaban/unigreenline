<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_calls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('called_at');
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound');
            $table->enum('outcome', ['answered', 'no_answer', 'busy', 'voicemail', 'wrong_number'])->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->date('next_follow_up')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'rep_id']);
            $table->index(['tenant_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_calls');
    }
};
