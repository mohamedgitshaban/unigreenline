<?php

namespace Modules\CRM\Imports;

use Illuminate\Validation\Rule;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;

/**
 * Customers are matched by their code within the tenant when the row has one, otherwise by name. A Sales Rep
 * can only import customers under their own name, mirroring the create endpoint.
 *
 * Also reads the company's "Customer Master Sheet" (Customer Code, acc.name, Customer Type, Customer Category,
 * ... , Active Status): its blank "NEEDED" cells are skipped, a missing category becomes "Other", and the
 * customer type (cash / account) is kept as the payment terms when none are given.
 */
class CustomerImport extends ImportDefinition
{
    private const TYPES = ['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other'];

    public function sheetNames(): array
    {
        return ['Customer Master Sheet', 'Customers'];
    }

    public function aliases(): array
    {
        return [
            'customer_code' => 'code',
            'accname' => 'name',
            'customer_category' => 'type',
            'district' => 'area',
            'full_address' => 'address',
            'payment_terms' => 'pay_terms',
            'active_status' => 'status',
            'sales_representative' => 'sales_rep_email',
        ];
    }

    public function prepare(array $rows, array $headings): array
    {
        $this->workbookFormat = in_array('customer_code', $headings, true);

        foreach ($rows as $sheetRow => $row) {
            $status = isset($row['status']) ? strtolower($row['status']) : null;
            $row['status'] = $status === 'collection-only' ? 'inactive' : $status;

            if ($this->workbookFormat) {
                $row['type'] ??= 'Other';
                $row['pay_terms'] ??= isset($row['customer_type']) ? (str_contains(strtolower($row['customer_type']), 'cash') ? 'Cash' : 'Account') : null;
            }

            $rows[$sheetRow] = $row;
        }

        return $rows;
    }

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
            'code' => ['nullable', 'string', 'max:40'],
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
            'sales_rep_email' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $repId = $this->resolveSalesRepId($row['sales_rep_email'] ?? null);

        $match = isset($row['code'])
            ? ['tenant_id' => $this->user->tenant_id, 'code' => $row['code']]
            : ['tenant_id' => $this->user->tenant_id, 'name' => $row['name']];

        return $this->upsertRecord(
            Customer::class,
            $match,
            [
                'code' => $row['code'] ?? null,
                'name' => $row['name'],
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

    /**
     * @param  ?string  $emailOrName  The rep's email, or their name as the company's sheet writes it.
     */
    private function resolveSalesRepId(?string $emailOrName): ?string
    {
        $scope = app(DataScope::class);

        if ($emailOrName === null) {
            return $scope->isTeamScoped($this->user) ? $this->user->id : null;
        }

        $rep = User::query()
            ->where('tenant_id', $this->user->tenant_id)
            ->where(fn ($query) => $query->where('email', $emailOrName)->orWhere('name', $emailOrName))
            ->first();

        if ($rep === null) {
            throw new ImportRowException('sales_rep_email', "No user with email or name {$emailOrName}.");
        }

        if (! $scope->canAssignTo($this->user, $rep->id)) {
            throw new ImportRowException('sales_rep_email', 'You can only import customers under your own name or a member of your team.');
        }

        return $rep->id;
    }
}
