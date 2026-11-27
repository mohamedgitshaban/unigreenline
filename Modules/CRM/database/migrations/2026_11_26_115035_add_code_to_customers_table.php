<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's own customer code (VP-32714): what its invoice and collection sheets refer to a
     * customer by, since two customers can share a name. Optional, unique per company when given.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('tenant_id');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
