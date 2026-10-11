<?php

namespace Modules\Recap\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RecapStockShortageException extends RuntimeException
{
    public function __construct(public readonly string $batchNo, public readonly int $requested, public readonly int $available)
    {
        parent::__construct("Batch {$batchNo} has only {$available} carton(s) left; the recap writes off {$requested}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
