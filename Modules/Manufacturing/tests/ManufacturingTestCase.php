<?php

namespace Modules\Manufacturing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Shared set-up for the manufacturing tests: one company with a warehouse, the
 * seeded roles and chart of accounts, and short helpers to build recipes and stock.
 */
abstract class ManufacturingTestCase extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    protected Tenant $tenant;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->ensureLedgerAccounts($this->tenant);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function product(string $name = null, array $attributes = []): Product
    {
        // No safety stock or reorder point unless a test asks for one: planning would otherwise want to top every product up.
        return Product::factory()->recycle($this->tenant)->create(array_filter(['name' => $name]) + $attributes + ['carton_qty' => 10, 'pack_cost_price' => 2, 'min_stock_cartons' => 0, 'reorder_level' => 0]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function stock(Product $product, int $cartons, float $cost = 10.0, array $attributes = []): InventoryBatch
    {
        return InventoryBatch::factory()->recycle($this->tenant)->create($attributes + [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'qty_cartons' => $cartons,
            'cost_per_carton' => $cost,
            'exp_date' => now()->addYear()->toDateString(),
        ]);
    }

    /**
     * A recipe for `$finished` taking `[Product, cartons per run, scrap %]` components.
     *
     * @param  array<int, array{0: Product, 1: int, 2?: float}>  $components
     * @param  array<string, mixed>  $attributes
     */
    protected function bom(Product $finished, array $components, array $attributes = []): Bom
    {
        $bom = Bom::factory()->recycle($this->tenant)->create(['product_id' => $finished->id] + $attributes);

        foreach ($components as $row) {
            $bom->lines()->create(['component_product_id' => $row[0]->id, 'qty_cartons' => $row[1], 'scrap_pct' => $row[2] ?? 0]);
        }

        return $bom->refresh();
    }

    /**
     * The ledger an order has made: per account code, total debits and credits across all its entries.
     *
     * @return array<string, array{debit: float, credit: float}>
     */
    protected function ledger(string $orderId): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.ref', $orderId)
            ->groupBy('journal_lines.account_code')
            ->selectRaw('journal_lines.account_code as code, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [$r->code => ['debit' => (float) $r->debit, 'credit' => (float) $r->credit]])->all();
    }

    /**
     * Each entry an order made, in order, as `code => [debit, credit]` lines.
     *
     * @return array<int, array<int, array{0: string, 1: float, 2: float}>>
     */
    protected function entries(string $orderId): array
    {
        return DB::table('journal_entries')->where('ref', $orderId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($entry) => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->get()
                ->map(fn ($l) => [$l->account_code, (float) $l->debit, (float) $l->credit])->all())
            ->all();
    }

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }
}
