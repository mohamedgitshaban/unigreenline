<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Support\DepreciationScheduleCalculator;

/**
 * Also used (with `$asset` on the route) to edit an asset, and — with the category optional — to preview a board.
 */
class StoreFixedAssetRequest extends FormRequest
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
        $editing = $this->route('asset') !== null;
        $previewing = str_ends_with($this->path(), '/preview');
        $required = $editing ? 'sometimes' : 'required';

        return [
            'name' => [$previewing ? 'sometimes' : $required, 'string', 'max:255'],
            'category_id' => [$previewing ? 'sometimes' : $required, 'string', Rule::exists('asset_categories', 'id')->where('tenant_id', $tenant)],
            'acquisition_date' => [$editing && ! $previewing ? 'sometimes' : 'required', 'date'],
            'depreciation_start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:acquisition_date'],
            'original_value' => [$editing && ! $previewing ? 'sometimes' : 'required', 'numeric', 'min:0.01', 'max:99999999999'],
            'salvage_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'method' => ['sometimes', Rule::in(DepreciationScheduleCalculator::METHODS)],
            'duration_periods' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'period_months' => ['sometimes', Rule::in([1, 2, 3, 4, 6, 12])],
            'declining_factor' => ['sometimes', 'numeric', 'gt:0', 'max:1'],
            'prorata' => ['sometimes', 'boolean'],
            'auto_post' => ['sometimes', 'boolean'],
            'asset_account_code' => ['sometimes', 'string', $account()],
            'accumulated_account_code' => ['sometimes', 'string', $account()],
            'expense_account_code' => ['sometimes', 'string', $account()],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_user_id' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
