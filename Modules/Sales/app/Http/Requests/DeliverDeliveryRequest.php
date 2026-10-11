<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Rules\UploadedFilePath;
use Modules\Sales\Models\Delivery;

class DeliverDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('delivery'));
    }

    /**
     * `invoice_data` is the delivered invoice file (e.g. the copy signed by
     * the customer), uploaded first via POST /deliveries/invoices.
     */
    public function rules(): array
    {
        return [
            'invoice_data' => ['nullable', 'string', new UploadedFilePath(
                Delivery::invoiceDirectory($this->user()->tenant_id), Delivery::class, 'invoice_data',
                'POST /deliveries/invoices', ignoreId: $this->route('delivery')->id,
            )],
        ];
    }
}
