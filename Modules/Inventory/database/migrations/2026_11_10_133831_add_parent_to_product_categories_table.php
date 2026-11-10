<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // A sub-class sits under a class. Two levels only (enforced by the requests), and a class that
            // still has sub-classes cannot be deleted.
            $table->foreignUlid('parent_id')->nullable()->after('tenant_id')->constrained('product_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
