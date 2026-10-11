<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Purchasing\Models\PurchaseOrder;

class UploadPurchaseOrderInvoiceRequest extends FormRequest
{
    /**
     * Uploading is step one of receiving, so it needs the receive permission.
     */
    public function authorize(): bool
    {
        return $this->user()->can('receive', PurchaseOrder::class);
    }

    public function rules(): array
    {
        return [
            'invoice' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
