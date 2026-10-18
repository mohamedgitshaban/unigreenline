<?php

namespace Modules\Manufacturing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A manufacturing business rule refused the request (wrong state, nothing left
 * to produce, ...). Always a 422 with a plain message and, optionally, details
 * such as the shortages that blocked it.
 */
class ManufacturingRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()] + $this->details, 422);
    }
}
