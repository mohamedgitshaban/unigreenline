<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'period_start' => $this->period_start->toDateString(),
            'depreciation_date' => $this->depreciation_date->toDateString(),
            'amount' => $this->amount,
            'accumulated_after' => $this->accumulated_after,
            'book_value_after' => $this->book_value_after,
            'status' => $this->status,
            'due' => $this->status === 'planned' && $this->depreciation_date->lte(today()),
            'journal_entry_id' => $this->journal_entry_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
        ];
    }
}
