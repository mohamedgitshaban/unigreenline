<?php

namespace Modules\Sales\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalesOrderLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only draft sales orders can be changed or deleted.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
