<?php

namespace Modules\Recap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Recap\Models\Recap;

class GenerateExpiredRecapsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Recap::class);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $this->user()->tenant_id)],
        ];
    }
}
