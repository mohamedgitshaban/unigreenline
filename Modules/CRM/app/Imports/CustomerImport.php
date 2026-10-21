<?php

namespace Modules\CRM\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;

/**
 * Customers are matched by name within the tenant. A Sales Rep can only
 * import customers under their own name, mirroring the create endpoint.
 */
class CustomerImport extends ImportDefinition
{
    private const TYPES = ['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other'];

    public function columns(): array
    {
        return [
            'Name', 'Type', 'Classification', 'Phone', 'Email', 'Governorate', 'Province', 'City',
            'Area', 'Address', 'Credit Limit', 'Pay Terms', 'Status', 'Sales Rep Email',
        ];
    }

    public function requiredColumns(): array
    {
        return ['Name', 'Type'];
    }

    public function exampleRow(): array
    {
        return ['Nile Vet Clinic', 'Clinic', 'A', '01000000000', 'clinic@example.com', 'Cairo', '', 'Nasr City', '', '12 Example St', 50000, 'Net 30', 'active', ''];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'classification' => ['nullable', Rule::in(['A+', 'A', 'B', 'C'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'pay_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sales_rep_email' => ['nullable', 'email'],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $repId = $this->resolveSalesRepId($row['sales_rep_email'] ?? null);

        return $this->upsertRecord(
            Customer::class,
            ['tenant_id' => $this->user->tenant_id, 'name' => $row['name']],
            [
                'sales_rep_id' => $repId,
                'type' => $row['type'],
                'classification' => $row['classification'] ?? null,
                'phone' => $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'governorate' => $row['governorate'] ?? null,
                'province' => $row['province'] ?? null,
                'city' => $row['city'] ?? null,
                'area' => $row['area'] ?? null,
                'address' => $row['address'] ?? null,
                'credit_limit' => $row['credit_limit'] ?? null,
                'pay_terms' => $row['pay_terms'] ?? null,
                'status' => $row['status'] ?? null,
            ],
            $updateExisting,
        );
    }

    public function module(): string
    {
        return 'crm';
    }

    public function entity(): string
    {
        return 'Customer';
    }

    private function resolveSalesRepId(?string $email): ?string
    {
        $scope = app(DataScope::class);

        if ($email === null) {
            return $scope->isTeamScoped($this->user) ? $this->user->id : null;
        }

        $rep = User::query()->where('tenant_id', $this->user->tenant_id)->where('email', $email)->first();

        if ($rep === null) {
            throw new ImportRowException('sales_rep_email', "No user with email {$email}.");
        }

        if (! $scope->canAssignTo($this->user, $rep->id)) {
            throw new ImportRowException('sales_rep_email', 'You can only import customers under your own name or a member of your team.');
        }

        return $rep->id;
    }
}
