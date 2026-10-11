<?php

namespace Modules\Inventory\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Product;

/**
 * Cross-field checks shared by product create and update: the packaging
 * ladder's shape, and the minimum selling price never exceeding the selling price.
 */
trait ChecksProductPackaging
{
    protected function packagingChecks(?Product $existing = null): \Closure
    {
        return function (Validator $validator) use ($existing) {
            foreach (array_values((array) $this->input('units', [])) as $index => $level) {
                $contains = $level['contains'] ?? null;

                if ($index === 0 && $contains !== null) {
                    $validator->errors()->add('units.0.contains', 'The smallest unit holds nothing smaller — leave contains empty.');
                }

                if ($index > 0 && $contains === null) {
                    $validator->errors()->add("units.{$index}.contains", 'Say how many of the previous unit this unit holds.');
                }
            }

            $min = $this->input('min_selling_price');
            $selling = $this->input('pack_selling_price', $existing?->pack_selling_price);

            if ($min !== null && $selling !== null && (float) $min > (float) $selling) {
                $validator->errors()->add('min_selling_price', 'The minimum selling price cannot be higher than the selling price.');
            }
        };
    }
}
