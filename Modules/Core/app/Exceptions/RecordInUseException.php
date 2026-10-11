<?php

namespace Modules\Core\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a record can't be deleted because other records still depend
 * on it (a database restrict-on-delete constraint, or a business rule).
 */
class RecordInUseException extends RuntimeException
{
    public function __construct(string $message = 'This record is in use and cannot be deleted.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
