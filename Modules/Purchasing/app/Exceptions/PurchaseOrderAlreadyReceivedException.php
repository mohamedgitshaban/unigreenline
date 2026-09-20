<?php

namespace Modules\Purchasing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PurchaseOrderAlreadyReceivedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This purchase order has already been received.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
