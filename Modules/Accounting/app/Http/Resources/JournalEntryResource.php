<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by' => UserResource::make($this->createdBy),
            'ref' => $this->ref,
            'description' => $this->description,
            'entry_date' => $this->entry_date->toDateString(),
            'type' => $this->type,
            'posted' => $this->posted,
            'reversal_of' => $this->reversal_of,
            'reversed_at' => $this->reversed_at,
            // Paginated at GET /journal-entries/{id}/lines.
            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}
