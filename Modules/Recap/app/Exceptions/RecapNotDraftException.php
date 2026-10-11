<?php

namespace Modules\Recap\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RecapNotDraftException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only draft recaps can be changed.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
