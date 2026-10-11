<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GiftBatchConflictException extends RuntimeException
{
    public function __construct(string $batchNo)
    {
        parent::__construct("Batch number {$batchNo} already exists for this product and warehouse as a different kind of stock (free gift vs. paid). Use a different batch number.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
