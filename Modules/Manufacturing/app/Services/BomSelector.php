<?php

namespace Modules\Manufacturing\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Models\Bom;

/**
 * Picks the recipe to make a product from when several exist: approved, not
 * archived, valid on the day, lowest priority number first, oldest first on a tie.
 */
class BomSelector
{
    public function forProduct(string $tenantId, string $productId, ?DateTimeInterface $date = null): ?Bom
    {
        return $this->query($tenantId, $productId, $date)->first();
    }

    /**
     * Every recipe that could make the product on that day, best first.
     *
     * @return Collection<int, Bom>
     */
    public function alternatives(string $tenantId, string $productId, ?DateTimeInterface $date = null): Collection
    {
        return $this->query($tenantId, $productId, $date)->get();
    }

    /**
     * @return Builder<Bom>
     */
    private function query(string $tenantId, string $productId, ?DateTimeInterface $date): Builder
    {
        $day = ($date ?? now())->format('Y-m-d');

        return Bom::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('active', true)
            ->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderBy('priority')->orderBy('created_at')->orderBy('id');
    }
}
