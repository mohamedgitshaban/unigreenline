<?php

namespace Modules\Core\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Nothing was imported: the file is unreadable, lacks required columns, or
 * one or more rows failed validation.
 */
class ImportFailedException extends RuntimeException
{
    /**
     * @param  array<int, array{row: int, errors: array<string, array<int, string>>}>  $rowErrors
     */
    public function __construct(string $message, public readonly array $rowErrors = [])
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => $this->rowErrors,
        ], 422);
    }
}
