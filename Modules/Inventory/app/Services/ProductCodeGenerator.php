<?php

namespace Modules\Inventory\Services;

use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\Product;

/**
 * The next free product code, PRD-00001, PRD-00002 … Codes someone typed by hand are skipped, never reused.
 */
class ProductCodeGenerator
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function next(string $tenantId): string
    {
        do {
            $code = $this->numbers->next($tenantId, 'product', 'PRD-', 5);
        } while (Product::query()->where('tenant_id', $tenantId)->where('sku', $code)->exists());

        return $code;
    }
}
