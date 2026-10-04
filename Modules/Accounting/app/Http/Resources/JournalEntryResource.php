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
            'posted' => $this->posted,
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
