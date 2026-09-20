<?php

namespace Modules\Purchasing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IncompleteReceiptException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Every purchase order line must have exactly one matching receipt entry.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
