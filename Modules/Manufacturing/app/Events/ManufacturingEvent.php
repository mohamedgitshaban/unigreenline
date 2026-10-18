<?php

namespace Modules\Manufacturing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Base of every manufacturing domain event. They are dispatched after the
 * database transaction that caused them commits, so a listener never hears about
 * something that was rolled back.
 */
abstract class ManufacturingEvent implements ManufacturingNotifiable, ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $productionOrderId = null,
        public readonly array $data = [],
    ) {}
}
