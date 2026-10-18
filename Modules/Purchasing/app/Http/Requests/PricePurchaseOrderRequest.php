<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Purchasing\Models\PurchaseOrder;

class PricePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('purchaseOrder')->tenant_id === $this->user()->tenant_id, 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'string', 'distinct'],
            'lines.*.cost_per_carton' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // For lines that were ordered in a packaging unit: the cost of one such unit.
            'lines.*.cost_per_unit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var PurchaseOrder $order */
                $order = $this->route('purchaseOrder');
                $orderLines = $order->lines()->get()->keyBy('id');
                $given = collect((array) $this->input('lines', []));

                foreach ($given as $index => $line) {
                    $orderLine = $orderLines->get($line['line_id'] ?? null);

                    if ($orderLine === null) {
                        $validator->errors()->add("lines.{$index}.line_id", 'That line does not belong to this purchase order.');

                        continue;
                    }

                    $hasCarton = ($line['cost_per_carton'] ?? null) !== null;
                    $hasUnit = ($line['cost_per_unit'] ?? null) !== null;

                    if ($hasCarton === $hasUnit) {
                        $validator->errors()->add("lines.{$index}.cost_per_carton", 'Send exactly one of cost_per_carton or cost_per_unit for each line.');
                    } elseif ($hasUnit && $orderLine->unit_id === null) {
                        $validator->errors()->add("lines.{$index}.cost_per_unit", 'This line was ordered in cartons — send cost_per_carton.');
                    }
                }

                if (! $validator->errors()->isNotEmpty() && $orderLines->keys()->diff($given->pluck('line_id'))->isNotEmpty()) {
                    $validator->errors()->add('lines', 'Every line of the purchase order must be priced.');
                }
            },
        ];
    }
}
