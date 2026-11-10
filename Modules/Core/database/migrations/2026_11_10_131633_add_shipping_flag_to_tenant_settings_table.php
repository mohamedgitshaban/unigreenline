<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            // true: shipping is the company's own cost and is not added to the invoice. false: it is charged to the customer.
            $table->boolean('shipping_cost_paid_by_company')->default(true)->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('shipping_cost_paid_by_company');
        });
    }
};
