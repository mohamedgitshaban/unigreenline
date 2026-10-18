<?php

namespace Modules\Purchasing\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Validator;

/**
 * Line costs are an administrator matter: a buyer raises the order with
 * quantities only, and the order waits in `pending_pricing` until someone
 * with `purchasing.pricing` sets the cost per carton.
 */
trait RestrictsCostsToPricers
{
    protected function costsOnlyFromPricers(): Closure
    {
        return function (Validator $validator) {
            if ($this->user()->can('purchase-orders.pricing')) {
                return;
            }

            foreach ((array) $this->input('lines', []) as $index => $line) {
                foreach (['cost_per_carton', 'cost_per_unit'] as $field) {
                    if (($line[$field] ?? null) !== null) {
                        $validator->errors()->add("lines.{$index}.{$field}", 'Only an administrator can set costs. Leave it out — the order will wait in pending pricing.');
                    }
                }
            }
        };
    }
}
