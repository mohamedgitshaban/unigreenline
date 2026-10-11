<?php

namespace Modules\Manufacturing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BatchNumberTakenException extends RuntimeException
{
    public function __construct(string $batchNo)
    {
        parent::__construct("Batch number {$batchNo} already exists in this warehouse. Choose another batch number for the finished goods.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
