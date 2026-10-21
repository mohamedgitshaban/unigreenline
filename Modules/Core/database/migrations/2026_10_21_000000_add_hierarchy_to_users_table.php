<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Who this person reports to. A manager sees their own records and everyone below them.
            $table->foreignUlid('manager_id')->nullable()->after('tenant_id')->constrained('users')->nullOnDelete();
            // How much of the company's records the person sees: all of them, or only their own and their team's.
            // null = by role (a Sales Rep sees their team, everyone else sees everything their permissions allow).
            $table->enum('data_scope', ['all', 'team'])->nullable()->after('status');

            $table->index(['tenant_id', 'manager_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'manager_id']);
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('data_scope');
        });
    }
};
