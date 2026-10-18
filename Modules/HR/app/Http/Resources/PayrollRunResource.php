<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'employees_count' => $this->employees_count,
            'total_earned' => $this->total_earned,
            'total_withheld' => $this->total_withheld,
            'total_net' => $this->total_net,
            'journal_entry_id' => $this->journal_entry_id,
            'payment_journal_entry_id' => $this->payment_journal_entry_id,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'approved_by' => $this->approvedBy ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name] : null,
            'approved_at' => $this->approved_at,
            'notes' => $this->notes,
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'created_at' => $this->created_at,
        ];
    }
}
