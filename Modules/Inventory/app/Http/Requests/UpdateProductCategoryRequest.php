<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('category'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('product_categories')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($this->route('category')),
            ],
            // A sub-class sits under a top-level class; there are two levels and no more.
            'parent_id' => ['nullable', 'string', Rule::exists('product_categories', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('parent_id')],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $category = $this->route('category');

                if ($this->filled('parent_id') && ! $validator->errors()->has('parent_id')) {
                    if ($this->input('parent_id') === $category->id) {
                        $validator->errors()->add('parent_id', 'A category cannot sit under itself.');
                    } elseif ($category->children()->exists()) {
                        $validator->errors()->add('parent_id', 'This class has sub-classes of its own, so it cannot become a sub-class.');
                    }
                }
            },
        ];
    }
}
