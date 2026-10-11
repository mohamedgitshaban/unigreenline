<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A quantity in a chosen unit can't be expressed in the product's stock
 * unit (e.g. 15 packets of a 30-packet carton), or the unit isn't one the
 * product is packaged in. Surfaces as an ordinary 422 on the offending field.
 */
class UnitConversionException extends ValidationException
{
    public static function forField(string $field, string $message): self
    {
        return self::withMessages([$field => $message]);
    }
}
