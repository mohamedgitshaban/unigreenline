<?php

namespace Modules\Sales\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a return needs to move stock against a specific batch (either
 * restocking a customer return or deducting a purchase return) but no batch
 * with that batch_no exists for the given product+warehouse. The returns
 * table has no expiry field, so a new batch can never be fabricated here —
 * unlike GRN, there is no legitimate way to create one from scratch.
 */
class ReturnBatchNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $productId, public readonly string $warehouseId, public readonly string $batchNo)
    {
        parent::__construct("No batch \"{$batchNo}\" found for this product in this warehouse.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
