<?php

namespace Modules\Inventory\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;

/**
 * Warehouses are matched by name within the tenant.
 *
 * Also reads the company's "Warehouse Master" sheet (a title row, then Warehouse Name and the Arabic columns
 * beside it): it gives no city or governorate, so those stay empty for a new warehouse.
 */
class WarehouseImport extends ImportDefinition
{
    public function sheetNames(): array
    {
        return ['Warehouse Master', 'Warehouses'];
    }

    public function aliases(): array
    {
        return ['warehouse_name' => 'name'];
    }

    public function prepare(array $rows, array $headings): array
    {
        $this->workbookFormat = in_array('warehouse_name', $headings, true);

        if (! $this->workbookFormat) {
            return $rows;
        }

        // Notes written beside the table (what each code means) share its rows but have no warehouse name.
        $rows = array_filter($rows, fn (array $row) => isset($row['name']));

        return array_map(fn (array $row) => $row + ['city' => null, 'governorate' => null], $rows);
    }

    public function columns(): array
    {
        return ['Name', 'City', 'Governorate', 'Address', 'Manager Email', 'Temperature', 'Capacity', 'Phone', 'Status'];
    }

    public function requiredColumns(): array
    {
        return ['Name', 'City', 'Governorate'];
    }

    public function exampleRow(): array
    {
        return ['Cairo Main', 'Cairo', 'Cairo', '5 Industrial Zone', '', '2-8C', 5000, '0220000000', 'active'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => [$this->workbookFormat ? 'nullable' : 'required', 'string', 'max:255'],
            'governorate' => [$this->workbookFormat ? 'nullable' : 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'manager_email' => ['nullable', 'email'],
            'temperature' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'maintenance'])],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $manager = null;

        if (isset($row['manager_email'])) {
            $manager = User::query()->where('tenant_id', $this->user->tenant_id)->where('email', $row['manager_email'])->first()
                ?? throw new ImportRowException('manager_email', "No user with email {$row['manager_email']}.");
        }

        $match = ['tenant_id' => $this->user->tenant_id, 'name' => $row['name']];
        $missing = Warehouse::query()->where($match)->doesntExist() ? '' : null;

        return $this->upsertRecord(
            Warehouse::class,
            $match,
            [
                'city' => $row['city'] ?? $missing,
                'governorate' => $row['governorate'] ?? $missing,
                'address' => $row['address'] ?? null,
                'manager_id' => $manager?->id,
                'manager_name' => $manager?->name,
                'temperature' => $row['temperature'] ?? null,
                'capacity' => $row['capacity'] ?? null,
                'phone' => $row['phone'] ?? null,
                'status' => $row['status'] ?? null,
            ],
            $updateExisting,
        );
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function entity(): string
    {
        return 'Warehouse';
    }
}
