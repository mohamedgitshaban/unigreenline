<?php

namespace Modules\Inventory\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;

/**
 * Warehouses are matched by name within the tenant.
 */
class WarehouseImport extends ImportDefinition
{
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
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['required', 'string', 'max:255'],
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

        return $this->upsertRecord(
            Warehouse::class,
            ['tenant_id' => $this->user->tenant_id, 'name' => $row['name']],
            [
                'city' => $row['city'],
                'governorate' => $row['governorate'],
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
