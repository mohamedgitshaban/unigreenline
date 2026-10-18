<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('routings')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'operations' => ['sometimes', 'array', 'max:100'],
            'operations.*.sequence' => ['required', 'integer', 'min:1', 'max:65535', 'distinct'],
            ...collect(RoutingRules::operation($tenantId, 'operations.*.'))->except(['operations.*.sequence'])->all(),
        ];
    }
}
