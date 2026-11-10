<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Support\WarehouseAccess;

class StockRequestPolicy
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user who sees every warehouse sees every request. Anyone else sees the requests for or from a warehouse
     * they work in, and the ones addressed to Purchasing or Manufacturing if they can raise those documents.
     * Tenant scoping is enforced in the controller.
     */
    public function view(User $user, StockRequest $stockRequest): bool
    {
        $visible = $this->access->idsFor($user);

        if ($visible === null) {
            return true;
        }

        if (in_array($stockRequest->warehouse_id, $visible, true) || in_array($stockRequest->from_warehouse_id, $visible, true)) {
            return true;
        }

        return match ($stockRequest->source) {
            'purchasing' => $user->can('purchase-orders.create'),
            'manufacturing' => $user->can('production-orders.create'),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return true;
    }
}
