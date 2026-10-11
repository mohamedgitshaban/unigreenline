<?php

namespace Modules\Purchasing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PurchaseOrderNotPricedException extends RuntimeException
{
    public function __construct(string $message = 'This purchase order is pending pricing — an administrator must set the line costs first.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
