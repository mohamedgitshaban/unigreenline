<?php

namespace Modules\Accounting\Exceptions;

use RuntimeException;

class UnknownAccountCodeException extends RuntimeException
{
    public function __construct(public readonly string $accountCode)
    {
        parent::__construct("No chart-of-accounts entry found for code \"{$accountCode}\".");
    }
}
