<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\Invoice;

/**
 * Invoices are an AR/finance document as much as a sales one — an
 * Accountant (accounting.* permissions, no sales.* per spec §2's role
 * table) needs to see them just as much as Sales Manager does.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $invoice->salesOrder?->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function void(User $user, Invoice $invoice): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $invoice->salesOrder?->sales_rep_id === $user->id;
        }

        return true;
    }
}
