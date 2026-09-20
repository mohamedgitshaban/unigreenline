<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    /**
     * @param  array<int, array{product_id: string, warehouse_id: string, needed: int, available: int}>  $shortfalls
     */
    public function __construct(public readonly array $shortfalls)
    {
        parent::__construct('Insufficient stock to satisfy every line.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'shortfalls' => $this->shortfalls,
        ], 422);
    }
}
