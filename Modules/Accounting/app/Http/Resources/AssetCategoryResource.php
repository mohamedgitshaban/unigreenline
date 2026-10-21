<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'asset_account_code' => $this->asset_account_code,
            'accumulated_account_code' => $this->accumulated_account_code,
            'expense_account_code' => $this->expense_account_code,
            'method' => $this->method,
            'duration_periods' => $this->duration_periods,
            'period_months' => $this->period_months,
            'declining_factor' => $this->declining_factor,
            'prorata' => $this->prorata,
            'salvage_pct' => $this->salvage_pct,
            'auto_post' => $this->auto_post,
            'active' => $this->active,
            'description' => $this->description,
            'assets_count' => $this->whenCounted('assets'),
        ];
    }
}
