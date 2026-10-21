<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Support\DepreciationScheduleCalculator;

class StoreAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->user()->tenant_id;
        $account = fn () => Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $tenant);
        $category = $this->route('category');

        return [
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('asset_categories', 'name')->where('tenant_id', $tenant)->ignore($category?->id)],
            'asset_account_code' => ['sometimes', 'string', $account()],
            'accumulated_account_code' => ['sometimes', 'string', $account()],
            'expense_account_code' => ['sometimes', 'string', $account()],
            'method' => ['sometimes', Rule::in(DepreciationScheduleCalculator::METHODS)],
            'duration_periods' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'period_months' => ['sometimes', Rule::in([1, 2, 3, 4, 6, 12])],
            'declining_factor' => ['sometimes', 'numeric', 'gt:0', 'max:1'],
            'prorata' => ['sometimes', 'boolean'],
            'salvage_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'auto_post' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
