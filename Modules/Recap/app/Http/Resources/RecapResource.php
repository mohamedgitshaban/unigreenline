<?php

namespace Modules\Recap\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'recap_date' => $this->recap_date->toDateString(),
            'notes' => $this->notes,
            'total_value' => $this->total_value,
            'lines' => RecapLineResource::collection($this->whenLoaded('lines')),
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'approved_by' => $this->approvedBy ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name] : null,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at,
        ];
    }
}
