<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;

/**
 * A manual (accountant-typed) journal entry: dated, described, with at least
 * two lines that each debit or credit one active, leaf account and together
 * balance exactly.
 */
class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'ref' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'string', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $lines = (array) $this->input('lines');
                $debits = 0.0;
                $credits = 0.0;
                $accounts = Account::query()->where('tenant_id', $this->user()->tenant_id)->whereIn('id', array_column($lines, 'account_id'))->withCount('children')->get()->keyBy('id');

                foreach ($lines as $index => $line) {
                    $debit = round((float) ($line['debit'] ?? 0), 2);
                    $credit = round((float) ($line['credit'] ?? 0), 2);

                    if (($debit > 0) === ($credit > 0)) {
                        $validator->errors()->add("lines.{$index}.debit", 'Each line must have either a debit or a credit amount, not both and not neither.');
                    }

                    $account = $accounts[$line['account_id']];

                    if (! $account->active) {
                        $validator->errors()->add("lines.{$index}.account_id", "Account {$account->code} is inactive.");
                    } elseif ($account->children_count > 0) {
                        $validator->errors()->add("lines.{$index}.account_id", "Account {$account->code} is a group heading — post to one of its sub-accounts.");
                    }

                    $debits += $debit;
                    $credits += $credit;
                }

                if ($validator->errors()->isEmpty() && round($debits, 2) !== round($credits, 2)) {
                    $validator->errors()->add('lines', 'The entry is not balanced: debits '.number_format($debits, 2, '.', '').' differ from credits '.number_format($credits, 2, '.', '').'.');
                }
            },
        ];
    }
}
