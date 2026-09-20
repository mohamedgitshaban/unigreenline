<?php

namespace Modules\Accounting\Exceptions;

use RuntimeException;

class UnbalancedJournalEntryException extends RuntimeException
{
    public function __construct(public readonly float $totalDebits, public readonly float $totalCredits)
    {
        parent::__construct(
            "Journal entry is not balanced: debits {$totalDebits} != credits {$totalCredits}."
        );
    }
}
