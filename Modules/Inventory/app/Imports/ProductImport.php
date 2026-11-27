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
 *
 * Also reads the company's "Product Master Sheet" (Product Code, Product Name English, Category, Packaging,
 * Standard Cost, Tax Category, Active Status ...). That sheet is the source of its categories, so a category
 * that doesn't exist yet is created, and a cell it still has to fill in ("NEEDED") falls back to a neutral
 * default: category "Uncategorized", pack unit "Pack", one pack per carton, zero prices.
 */
class ProductImport extends ImportDefinition
{
    public function sheetNames(): array
    {
        return ['Product Master Sheet', 'Products'];
    }

    public function aliases(): array
    {
        return [
            'product_code' => 'sku',
            'product_name_english' => 'name',
            'product_name_arabic' => 'name_arabic',
            'packaging' => 'pack_unit',
            'standard_cost' => 'pack_cost_price',
            'cost_price' => 'pack_cost_price',
            'sales_price' => 'pack_selling_price',
            'minimum_stock' => 'min_stock_cartons',
            'active_status' => 'active',
        ];
    }

    public function prepare(array $rows, array $headings): array
    {
        $this->workbookFormat = in_array('product_code', $headings, true);

        foreach ($rows as $sheetRow => $row) {
            $active = isset($row['active']) ? strtolower($row['active']) : null;
            $row['active'] = match ($active) {
                'active' => '1',
                'inactive' => '0',
                default => $active,
            };

            if ($this->workbookFormat) {
                $row['name'] ??= $row['name_arabic'] ?? null;
                $row['category'] ??= 'Uncategorized';
                $row['pack_unit'] ??= $row['default_sales_unit'] ?? 'Pack';
                $row['carton_qty'] ??= '1';
                $row['pack_cost_price'] ??= '0';
                $row['pack_selling_price'] ??= $row['retail_price'] ?? '0';
                $row['tax_pct'] ??= isset($row['tax_category']) && str_contains(strtolower($row['tax_category']), 'non') ? '0' : null;
            }

            $rows[$sheetRow] = $row;
        }

        return $rows;
    }

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

        if ($category === null && $this->workbookFormat) {
            $category = ProductCategory::create(['tenant_id' => $tenantId, 'name' => $row['category']]);
        }

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
