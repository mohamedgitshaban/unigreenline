<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    /**
     * @param  array<int, array{product_id: string, warehouse_id: string, needed: int, available: int, gift?: bool}>  $shortfalls
     */
    public function __construct(public readonly array $shortfalls)
    {
        parent::__construct('Insufficient stock to satisfy every line.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'shortfalls' => $this->named(),
        ], 422);
    }

    /**
     * The shortfalls with the product's name and SKU and the warehouse's name added, so the message can say which line is short.
     *
     * @return array<int, array<string, mixed>>
     */
    public function named(): array
    {
        $products = Product::query()->whereIn('id', array_column($this->shortfalls, 'product_id'))->get(['id', 'name', 'sku'])->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', array_column($this->shortfalls, 'warehouse_id'))->pluck('name', 'id');

        return array_map(fn (array $shortfall) => $shortfall + [
            'product_name' => $products[$shortfall['product_id']]->name ?? null,
            'sku' => $products[$shortfall['product_id']]->sku ?? null,
            'warehouse_name' => $warehouses[$shortfall['warehouse_id']] ?? null,
        ], $this->shortfalls);
    }
}
