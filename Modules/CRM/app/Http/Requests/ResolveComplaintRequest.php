<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resolve', $this->route('complaint'));
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'max:2000'],
        ];
    }
}
