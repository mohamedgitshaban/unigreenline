<?php

namespace Modules\Inventory\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Purchasing\Models\Supplier;

/**
 * Products are matched by SKU within the tenant. Category (by name or code)
 * and supplier (by name) must already exist.
 */
class ProductImport extends ImportDefinition
{
    public function columns(): array
    {
        return [
            'SKU', 'Name', 'Category', 'Supplier', 'Brand', 'Pack Unit', 'Carton Qty', 'Pack Cost Price',
            'Pack Selling Price', 'Min Selling Price', 'Discount Pct', 'Tax Pct', 'Min Stock Cartons', 'Reorder Level', 'Active',
        ];
    }

    public function requiredColumns(): array
    {
        return ['SKU', 'Name', 'Category', 'Pack Unit', 'Carton Qty', 'Pack Cost Price', 'Pack Selling Price'];
    }

    public function exampleRow(): array
    {
        return ['AMX-100', 'Amoxicillin 100ml', 'Antibiotics', 'Acme Pharma', 'Acme', 'Bottle', 24, 40, 55, 50, 0, 14, 10, 20, 'yes'];
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string'],
            'supplier' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:255'],
            'pack_unit' => ['required', 'string', 'max:255'],
            'carton_qty' => ['required', 'integer', 'min:1'],
            'pack_cost_price' => ['required', 'numeric', 'min:0'],
            'pack_selling_price' => ['required', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0', 'lte:pack_selling_price'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock_cartons' => ['nullable', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', Rule::in(['1', '0', 'true', 'false', 'yes', 'no'])],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $tenantId = $this->user->tenant_id;

        $category = ProductCategory::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('name', $row['category'])->orWhere('code', $row['category']))
            ->first();

        if ($category === null) {
            throw new ImportRowException('category', "No product category named or coded \"{$row['category']}\".");
        }

        $supplierId = null;

        if (isset($row['supplier'])) {
            $supplierId = Supplier::query()->where('tenant_id', $tenantId)->where('name', $row['supplier'])->value('id')
                ?? throw new ImportRowException('supplier', "No supplier named \"{$row['supplier']}\".");
        }

        return $this->upsertRecord(
            Product::class,
            ['tenant_id' => $tenantId, 'sku' => $row['sku']],
            [
                'category_id' => $category->id,
                'supplier_id' => $supplierId,
                'name' => $row['name'],
                'brand' => $row['brand'] ?? null,
                'pack_unit' => $row['pack_unit'],
                'carton_qty' => $row['carton_qty'],
                'pack_cost_price' => $row['pack_cost_price'],
                'pack_selling_price' => $row['pack_selling_price'],
                'min_selling_price' => $row['min_selling_price'] ?? null,
                'discount_pct' => $row['discount_pct'] ?? null,
                'tax_pct' => $row['tax_pct'] ?? null,
                'min_stock_cartons' => $row['min_stock_cartons'] ?? null,
                'reorder_level' => $row['reorder_level'] ?? null,
                'active' => $this->toBool($row['active'] ?? null),
            ],
            $updateExisting,
        );
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function entity(): string
    {
        return 'Product';
    }
}
