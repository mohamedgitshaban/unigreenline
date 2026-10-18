<?php

namespace Modules\Inventory\Tests\Feature\Http\GoodsReceipt;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class GoodsReceiptLedgerTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    public function test_a_manual_receipt_puts_its_value_into_inventory_against_opening_balance_equity(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($tenant);
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $this->postJson('/api/v1/inventory/grn', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'OPEN-1',
            'qty_cartons' => 5, 'cost_per_carton' => 120, 'exp_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertSame('600.00', Account::query()->where('tenant_id', $tenant->id)->where('code', '1300')->first()->balance);
        $this->assertSame('600.00', Account::query()->where('tenant_id', $tenant->id)->where('code', '3300')->first()->balance);
    }
}
