<?php

namespace Modules\Recap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('recap')->tenant_id === $this->user()->tenant_id, 404);

        return $this->user()->can('approve', $this->route('recap'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
