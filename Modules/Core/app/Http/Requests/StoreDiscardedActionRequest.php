<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscardedActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
        ];
    }
}
