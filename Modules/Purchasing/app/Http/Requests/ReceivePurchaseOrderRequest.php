<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('receive', $this->route('purchaseOrder'));
    }

    public function rules(): array
    {
        return [
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.line_id' => ['required', 'string'],
            'receipts.*.batch_no' => ['required', 'string', 'max:255'],
            // No default/fabricated expiry — required per line (spec §5.2).
            'receipts.*.exp_date' => ['required', 'date'],
            'receipts.*.mfg_date' => ['nullable', 'date', 'before_or_equal:receipts.*.exp_date'],
            'receipts.*.rcv_date' => ['nullable', 'date'],
        ];
    }
}
