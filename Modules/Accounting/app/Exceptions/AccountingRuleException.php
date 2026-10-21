<?php

namespace Modules\Accounting\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An accounting business rule refused the request (wrong state of an asset or loan, an account that does not exist, ...).
 * Always a 422 with a plain message.
 */
class AccountingRuleException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
