<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('warehouse'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'governorate' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'manager_id' => [
                'nullable', 'string',
                Rule::exists('users', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'temperature' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'maintenance'])],
        ];
    }
}
