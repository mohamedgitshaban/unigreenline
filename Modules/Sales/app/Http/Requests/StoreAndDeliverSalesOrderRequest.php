<?php

namespace Modules\Sales\Http\Requests;

use Modules\Core\Rules\UploadedFilePath;
use Modules\Sales\Models\Delivery;

/**
 * Everything POST /sales-orders takes (so the same lines, warehouse, gift,
 * unit and minimum-price rules apply), plus an optional `delivery` block
 * describing the hand-over.
 */
class StoreAndDeliverSalesOrderRequest extends StoreSalesOrderRequest
{
    public function authorize(): bool
    {
        // Guarded by the route's sales-orders.quick-deliver permission.
        return true;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'delivery' => ['sometimes', 'array'],
            'delivery.driver' => ['nullable', 'string', 'max:255'],
            'delivery.delivery_date' => ['nullable', 'date'],
            'delivery.notes' => ['nullable', 'string', 'max:2000'],
            // The signed / delivered invoice copy, uploaded first via POST /deliveries/invoices.
            'delivery.invoice_data' => ['nullable', 'string', new UploadedFilePath(
                Delivery::invoiceDirectory($this->user()->tenant_id), Delivery::class, 'invoice_data',
                'POST /deliveries/invoices',
            )],
        ];
    }
}
