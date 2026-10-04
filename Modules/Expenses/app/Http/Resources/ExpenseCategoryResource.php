<?php

namespace Modules\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'active' => $this->active,
            'account' => [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ],
        ];
    }
}
