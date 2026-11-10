<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\ReturnRecord;

class StoreInvoiceReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ReturnRecord::class);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['Sales Return', 'Damaged', 'Expired Return', 'Wrong Item'])],
            // Whether the goods go back into sellable stock. A damaged or expired return is usually written off instead.
            'restocked' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'return_date' => ['sometimes', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            // The id of one of the invoice's lines (GET /invoices/{id}/lines), and how many of that line's units come back.
            'lines.*.line_id' => ['required', 'string', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
