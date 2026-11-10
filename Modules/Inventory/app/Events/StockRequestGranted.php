<?php

namespace Modules\Inventory\Events;

use Modules\Inventory\Models\StockRequest;

/**
 * Fired when a stock request for Purchasing or Manufacturing is granted. Inventory does not depend on those
 * modules, so the one that owns the document listens and sets the outcome: a draft purchase order or a draft
 * production order. A listener that handles the request calls {@see resolveWith()}.
 */
class StockRequestGranted
{
    /** @var array{type: string, id: string}|null */
    public ?array $result = null;

    /**
     * @param  array<string, mixed>  $options  What the granter chose, e.g. supplier_id or bom_id.
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function __construct(
        public readonly StockRequest $stockRequest,
        public readonly array $options,
        public readonly array $actor,
    ) {}

    public function resolveWith(string $type, string $id): void
    {
        $this->result = ['type' => $type, 'id' => $id];
    }
}
