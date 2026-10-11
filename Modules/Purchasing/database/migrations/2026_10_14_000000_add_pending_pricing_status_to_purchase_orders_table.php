<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WITH = "'draft','sent','pending','pending_pricing','received','cancelled'";

    private const WITHOUT = "'draft','sent','pending','received','cancelled'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE purchase_orders MODIFY status ENUM('.self::WITH.") NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('purchase_orders')->where('status', 'pending_pricing')->update(['status' => 'draft']);
            DB::statement('ALTER TABLE purchase_orders MODIFY status ENUM('.self::WITHOUT.") NOT NULL DEFAULT 'draft'");
        }
    }
};
