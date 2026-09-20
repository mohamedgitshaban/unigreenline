<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('salesOrder'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['picking', 'invoiced', 'delivered', 'cancelled'])],
        ];
    }
}
