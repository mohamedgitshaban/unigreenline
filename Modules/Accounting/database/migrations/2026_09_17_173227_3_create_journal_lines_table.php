<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('account_code');
            $table->string('account_name');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
        });

        // A line is either a debit or a credit, never both, and never
        // neither (spec §4.13). MariaDB 10.2.1+/MySQL 8.0.16+ enforce CHECK
        // constraints; this is a second line of defense behind the service
        // layer's own validation, not the only one.
        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_debit_xor_credit '.
            'CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
