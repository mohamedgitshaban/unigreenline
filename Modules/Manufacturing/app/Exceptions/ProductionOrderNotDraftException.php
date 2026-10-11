<?php

namespace Modules\Manufacturing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductionOrderNotDraftException extends RuntimeException
{
    public function __construct(string $message = 'Only draft production orders can be changed.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
