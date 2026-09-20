<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ref')->nullable();
            $table->text('description')->nullable();
            $table->date('entry_date');
            $table->boolean('posted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'entry_date']);
            $table->index('ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
