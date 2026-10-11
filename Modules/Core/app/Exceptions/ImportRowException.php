<?php

namespace Modules\Core\Exceptions;

use RuntimeException;

/**
 * A row passed validation but can't be persisted (e.g. a referenced
 * category name doesn't exist). Carries the offending column for the report.
 */
class ImportRowException extends RuntimeException
{
    public function __construct(public readonly string $column, string $message)
    {
        parent::__construct($message);
    }
}
