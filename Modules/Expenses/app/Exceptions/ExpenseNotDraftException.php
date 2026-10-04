<?php

namespace Modules\Expenses\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExpenseNotDraftException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only draft expenses can be changed.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
