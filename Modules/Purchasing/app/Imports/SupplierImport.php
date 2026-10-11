<?php

namespace Modules\Purchasing\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Imports\ImportDefinition;
use Modules\Purchasing\Models\Supplier;

/**
 * Suppliers are matched by name within the tenant. Contract files can't be
 * imported — attach them afterwards via POST /suppliers/contracts.
 */
class SupplierImport extends ImportDefinition
{
    public function columns(): array
    {
        return ['Name', 'Country', 'City', 'Address', 'Contact', 'Email', 'Phone', 'Pay Terms', 'Currency', 'Rating', 'Status'];
    }

    public function requiredColumns(): array
    {
        return ['Name'];
    }

    public function exampleRow(): array
    {
        return ['Acme Pharma', 'Egypt', 'Cairo', '10 Factory Rd', 'Mona Ali', 'sales@acme.example', '01111111111', 'Net 30', 'EGP', 4, 'active'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'pay_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        return $this->upsertRecord(
            Supplier::class,
            ['tenant_id' => $this->user->tenant_id, 'name' => $row['name']],
            array_diff_key($row, ['name' => true]),
            $updateExisting,
        );
    }

    public function module(): string
    {
        return 'purchasing';
    }

    public function entity(): string
    {
        return 'Supplier';
    }
}
