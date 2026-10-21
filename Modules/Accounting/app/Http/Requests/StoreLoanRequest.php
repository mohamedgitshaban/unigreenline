<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Support\LoanScheduleCalculator;

/**
 * Also used (with `$loan` on the route) to edit a draft loan and, as a preview, to see a schedule before agreeing one.
 */
class StoreLoanRequest extends FormRequest
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
        $required = $this->route('loan') !== null ? 'sometimes' : 'required';
        $previewing = str_ends_with($this->path(), '/preview');

        return [
            'name' => [$previewing ? 'sometimes' : $required, 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(['borrowed', 'granted'])],
            'counterparty_type' => ['sometimes', 'nullable', Rule::in(['supplier', 'customer', 'employee', 'other'])],
            'counterparty_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'counterparty_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'principal' => [$required, 'numeric', 'min:0.01', 'max:99999999999'],
            'annual_rate_pct' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'method' => ['sometimes', Rule::in(LoanScheduleCalculator::METHODS)],
            'periods' => [$required, 'integer', 'min:1', 'max:600'],
            'period_months' => ['sometimes', Rule::in([1, 2, 3, 4, 6, 12])],
            'grace_periods' => ['sometimes', 'integer', 'min:0'],
            'start_date' => [$required, 'date'],
            'first_due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'disbursement_account_code' => ['sometimes', 'nullable', 'string', $account()],
            'principal_account_code' => ['sometimes', 'string', $account()],
            'interest_account_code' => ['sometimes', 'string', $account()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $periods = $this->input('periods', $this->route('loan')?->periods);
                $grace = (int) $this->input('grace_periods', $this->route('loan')?->grace_periods ?? 0);

                if ($periods !== null && $grace >= (int) $periods) {
                    $validator->errors()->add('grace_periods', 'The grace period has to be shorter than the loan.');
                }
            },
        ];
    }
}
