<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The rules the create and edit requests share for the parts of a recipe beyond the basics.
 */
class BomRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function extras(string $tenantId, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'routing_id' => ['nullable', 'string', Rule::exists('routings', 'id')->where('tenant_id', $tenantId)],
            'instructions' => ['nullable', 'string', 'max:10000'],

            'lines' => [$presence, 'array', 'min:1'],
            'lines.*.component_product_id' => ['required', 'string', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            // Either whole cartons, or a quantity in one of the component's packaging units.
            'lines.*.qty_cartons' => ['required_without:lines.*.unit_id', 'nullable', 'integer', 'min:1'],
            'lines.*.unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'lines.*.unit_qty' => ['required_with:lines.*.unit_id', 'nullable', 'integer', 'min:1'],
            'lines.*.scrap_pct' => ['sometimes', 'numeric', 'min:0', 'max:99.99'],
            'lines.*.optional' => ['sometimes', 'boolean'],
            'lines.*.operation_id' => ['nullable', 'string', Rule::exists('routing_operations', 'id')],

            'by_products' => ['sometimes', 'array'],
            'by_products.*.product_id' => ['required', 'string', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'by_products.*.qty_cartons' => ['required', 'integer', 'min:1'],
            'by_products.*.cost_share_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'by_products.*.warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
