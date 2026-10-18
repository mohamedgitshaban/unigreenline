<?php

namespace Modules\Manufacturing\Exceptions;

class InvalidOrderTransitionException extends ManufacturingRuleException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("A manufacturing order that is {$from} cannot become {$to}.", ['from' => $from, 'to' => $to]);
    }
}
