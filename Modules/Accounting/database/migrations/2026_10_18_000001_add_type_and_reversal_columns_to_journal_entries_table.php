<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // system = posted automatically by a business flow, manual = typed by an accountant,
            // reversal = the mirror image that cancels a manual entry.
            $table->string('type', 20)->default('system')->after('ref');
            $table->ulid('reversal_of')->nullable()->after('type')->index();
            $table->timestamp('reversed_at')->nullable()->after('posted');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['type', 'reversal_of', 'reversed_at']);
        });
    }
};
