<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\FixedAsset;

class FixedAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FixedAsset $this */
        $depreciable = round((float) $this->original_value - (float) $this->salvage_value, 2);
        $next = $this->relationLoaded('depreciations') ? $this->depreciations->firstWhere('status', 'planned') : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'status' => $this->status,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'acquisition_date' => $this->acquisition_date->toDateString(),
            'depreciation_start_date' => $this->depreciation_start_date->toDateString(),
            'original_value' => $this->original_value,
            'salvage_value' => $this->salvage_value,
            'accumulated_depreciation' => $this->accumulated_depreciation,
            'book_value' => number_format($this->bookValue(), 2, '.', ''),
            'depreciable_value' => number_format($depreciable, 2, '.', ''),
            'depreciated_pct' => $depreciable > 0 ? round((float) $this->accumulated_depreciation / $depreciable * 100, 1) : 100.0,
            'method' => $this->method,
            'duration_periods' => $this->duration_periods,
            'period_months' => $this->period_months,
            'declining_factor' => $this->declining_factor,
            'prorata' => $this->prorata,
            'auto_post' => $this->auto_post,
            'accounts' => ['asset' => $this->asset_account_code, 'accumulated_depreciation' => $this->accumulated_account_code, 'depreciation_expense' => $this->expense_account_code],
            'supplier_id' => $this->supplier_id,
            'reference' => $this->reference,
            'serial_number' => $this->serial_number,
            'location' => $this->location,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name] : null),
            'notes' => $this->notes,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'acquisition_journal_entry_id' => $this->acquisition_journal_entry_id,
            'disposal' => $this->disposal_date ? [
                'date' => $this->disposal_date->toDateString(), 'value' => $this->disposal_value, 'reason' => $this->disposal_reason,
                'journal_entry_id' => $this->disposal_journal_entry_id,
            ] : null,
            'next_depreciation' => $next ? ['sequence' => $next->sequence, 'date' => $next->depreciation_date->toDateString(), 'amount' => $next->amount] : null,
            'depreciations_count' => $this->whenCounted('depreciations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
