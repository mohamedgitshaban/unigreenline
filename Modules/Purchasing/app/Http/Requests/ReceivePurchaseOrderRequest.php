<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Rules\UploadedFilePath;
use Modules\Purchasing\Models\PurchaseOrder;

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
            // Bonus stock the supplier delivered on top of the order. It goes into stock at zero
            // cost as free-gift batches, which only gift lines on a sales order can sell.
            'free_gifts' => ['sometimes', 'array'],
            'free_gifts.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'free_gifts.*.unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $this->user()->tenant_id)],
            // In the chosen unit, or in cartons when no unit is given.
            'free_gifts.*.qty' => ['required', 'integer', 'min:1'],
            'free_gifts.*.batch_no' => ['required', 'string', 'max:255'],
            'free_gifts.*.exp_date' => ['required', 'date'],
            'free_gifts.*.mfg_date' => ['nullable', 'date', 'before_or_equal:free_gifts.*.exp_date'],
            // The supplier's own invoice that came with the goods — optional.
            'invoice_data' => ['nullable', 'array'],
            'invoice_data.invoice_no' => ['required_with:invoice_data', 'string', 'max:255'],
            'invoice_data.invoice_date' => ['nullable', 'date'],
            'invoice_data.amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'invoice_data.file_path' => ['nullable', 'string', new UploadedFilePath(
                PurchaseOrder::invoiceDirectory($this->user()->tenant_id), PurchaseOrder::class, 'supplier_invoice_path',
                'POST /purchase-orders/invoices',
            )],
        ];
    }
}
