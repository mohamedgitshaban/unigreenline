<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Warehouse;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Warehouse::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['required', 'string', 'max:255'],
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
