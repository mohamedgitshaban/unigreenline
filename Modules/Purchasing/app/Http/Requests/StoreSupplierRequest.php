<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Rules\UploadedFilePath;
use Modules\Purchasing\Models\Supplier;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers')->where('tenant_id', $this->user()->tenant_id)],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'pay_terms' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'address' => ['nullable', 'string', 'max:255'],
            'contract_data' => ['nullable', 'string', new UploadedFilePath(
                Supplier::contractDirectory($this->user()->tenant_id), Supplier::class, 'contract_data',
                'POST /suppliers/contracts', Supplier::CONTRACT_EXTENSIONS,
            )],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
