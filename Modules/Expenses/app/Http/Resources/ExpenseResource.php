<?php

namespace Modules\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => ExpenseCategoryResource::make($this->category),
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null,
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'payee' => $this->payee,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'expense_date' => $this->expense_date->toDateString(),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'description' => $this->description,
            'receipt_path' => $this->receipt_path,
            'receipt_url' => $this->receipt_path !== null ? url("/api/v1/expenses/{$this->id}/receipt") : null,
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'approved_by' => $this->approvedBy ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name] : null,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at,
        ];
    }
}
