<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\Loan;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Loan $this */
        $next = $this->relationLoaded('installments') ? $this->installments->firstWhere('status', 'planned') : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'kind' => $this->kind,
            'status' => $this->status,
            'counterparty' => ['type' => $this->counterparty_type, 'id' => $this->counterparty_id, 'name' => $this->counterparty_name],
            'principal' => $this->principal,
            'annual_rate_pct' => $this->annual_rate_pct,
            'method' => $this->method,
            'periods' => $this->periods,
            'period_months' => $this->period_months,
            'grace_periods' => $this->grace_periods,
            'start_date' => $this->start_date->toDateString(),
            'first_due_date' => $this->first_due_date->toDateString(),
            'accounts' => ['disbursement' => $this->disbursement_account_code, 'principal' => $this->principal_account_code, 'interest' => $this->interest_account_code],
            'outstanding_principal' => $this->outstanding_principal,
            'interest_paid' => $this->interest_paid,
            'installments_count' => $this->whenCounted('installments'),
            'installments_paid' => $this->whenCounted('paidInstallments'),
            'next_installment' => $next ? [
                'sequence' => $next->sequence, 'due_date' => $next->due_date->toDateString(), 'payment_amount' => $next->payment_amount,
                'overdue' => $this->status === 'active' && $next->due_date->lt(today()),
            ] : null,
            'notes' => $this->notes,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'disbursement_journal_entry_id' => $this->disbursement_journal_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
