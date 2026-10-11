<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Purchasing\Models\Supplier;

class UploadSupplierContractRequest extends FormRequest
{
    /**
     * Uploading is step one of both creating and editing a supplier.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class) || $this->user()->can('purchasing.edit');
    }

    public function rules(): array
    {
        return [
            'contract' => ['required', 'file', 'mimes:'.implode(',', Supplier::CONTRACT_EXTENSIONS), 'max:10240'],
        ];
    }
}
