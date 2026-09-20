<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
