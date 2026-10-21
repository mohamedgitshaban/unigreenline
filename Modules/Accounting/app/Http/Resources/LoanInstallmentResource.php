<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'due_date' => $this->due_date->toDateString(),
            'opening_balance' => $this->opening_balance,
            'principal_amount' => $this->principal_amount,
            'interest_amount' => $this->interest_amount,
            'payment_amount' => $this->payment_amount,
            'closing_balance' => $this->closing_balance,
            'status' => $this->status,
            'overdue' => $this->status === 'planned' && $this->due_date->lt(today()),
            'paid_on' => $this->paid_on?->toDateString(),
            'journal_entry_id' => $this->journal_entry_id,
            'loan' => $this->whenLoaded('loan', fn () => ['id' => $this->loan->id, 'number' => $this->loan->number, 'name' => $this->loan->name, 'kind' => $this->loan->kind, 'counterparty_name' => $this->loan->counterparty_name]),
        ];
    }
}
