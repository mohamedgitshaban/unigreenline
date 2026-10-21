<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Support\DataScope;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $userId = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'manager_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId), function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && app(DataScope::class)->wouldCreateLoop($this->route('user'), $value)) {
                    $fail('A person cannot report to themselves or to someone on their own team.');
                }
            }],
            'data_scope' => ['nullable', Rule::in(['all', 'team'])],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
