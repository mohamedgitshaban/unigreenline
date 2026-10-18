<?php

namespace Modules\Purchasing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidSupplierPaymentException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => ['amount' => [$this->getMessage()]]], 422);
    }
}
