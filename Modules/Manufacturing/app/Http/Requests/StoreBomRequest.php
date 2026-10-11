<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\Models\Bom;

class StoreBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Bom::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('boms')->where('tenant_id', $tenantId)],
            'output_qty_cartons' => ['sometimes', 'integer', 'min:1'],
            'conversion_cost' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_product_id' => ['required', 'string', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lines.*.qty_cartons' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->ensureProductIsNotItsOwnComponent($validator, $this->input('product_id'));
            },
        ];
    }

    protected function ensureProductIsNotItsOwnComponent(Validator $validator, ?string $productId): void
    {
        foreach ((array) $this->input('lines', []) as $index => $line) {
            if ($productId !== null && ($line['component_product_id'] ?? null) === $productId) {
                $validator->errors()->add("lines.{$index}.component_product_id", 'A product cannot be a component of itself.');
            }
        }
    }
}
