<?php

namespace Modules\HR\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An HR business rule refused the request (a request that is no longer pending,
 * payroll asked for before the month ended...). Always a plain 422 with a message.
 */
class HrRuleException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
