<?php

namespace Modules\CRM\Services;

use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;

/**
 * Finds customers that look like the one being created: same phone number, same e-mail, or same name in the same city.
 * Each match says whom it belongs to, so the user can see whose customer it is before creating a second copy.
 */
class CustomerDuplicateFinder
{
    /** Numbers are compared on their last nine digits, so +20 100 123 4567 and 0100 123 4567 are the same phone. */
    private const PHONE_TAIL = 9;

    public function __construct(private readonly DataScope $scope) {}

    /**
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, city?: ?string}  $candidate
     * @return Collection<int, array{customer: Customer, matched_on: array<int, string>}>
     */
    public function matches(string $tenantId, array $candidate, ?string $ignoreId = null): Collection
    {
        $digits = $this->phoneKey($candidate['phone'] ?? null);
        $email = mb_strtolower(trim((string) ($candidate['email'] ?? '')));
        $name = mb_strtolower(trim((string) ($candidate['name'] ?? '')));
        $city = mb_strtolower(trim((string) ($candidate['city'] ?? '')));

        $matchesPhone = strlen($digits) >= 6;
        $tail = strlen($digits) >= self::PHONE_TAIL;
        $matchesName = $name !== '' && $city !== '';

        if (! $matchesPhone && $email === '' && ! $matchesName) {
            return collect();
        }

        $found = Customer::query()
            ->with('salesRep:id,name')
            ->where('tenant_id', $tenantId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($matchesPhone, $tail, $digits, $email, $matchesName, $name, $city) {
                $query->when($matchesPhone, fn ($q) => $q->orWhereRaw(
                    $tail ? "RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), ".self::PHONE_TAIL.') = ?' : "REGEXP_REPLACE(phone, '[^0-9]', '') = ?",
                    [$digits],
                ))
                    ->when($email !== '', fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$email]))
                    ->when($matchesName, fn ($q) => $q->orWhere(fn ($same) => $same->whereRaw('LOWER(name) = ?', [$name])->whereRaw('LOWER(city) = ?', [$city])));
            })
            ->limit(10)
            ->get();

        return $found->map(function (Customer $customer) use ($matchesPhone, $digits, $email, $matchesName, $name, $city) {
            $on = [];

            if ($matchesPhone && $this->phoneKey($customer->phone) === $digits) {
                $on[] = 'phone';
            }

            if ($email !== '' && mb_strtolower((string) $customer->email) === $email) {
                $on[] = 'email';
            }

            if ($matchesName && mb_strtolower($customer->name) === $name && mb_strtolower((string) $customer->city) === $city) {
                $on[] = 'name_and_city';
            }

            return ['customer' => $customer, 'matched_on' => $on];
        })->values();
    }

    /**
     * The matches as the API shows them. A customer the viewer may not open is shown without its id.
     *
     * @param  Collection<int, array{customer: Customer, matched_on: array<int, string>}>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $matches, User $viewer): array
    {
        return $matches->map(function (array $match) use ($viewer) {
            /** @var Customer $customer */
            $customer = $match['customer'];
            $visible = $this->scope->canSee($viewer, $customer->sales_rep_id);

            return [
                'id' => $visible ? $customer->id : null,
                'name' => $customer->name,
                'city' => $customer->city,
                'phone' => $customer->phone,
                'matched_on' => $match['matched_on'],
                'sales_rep' => $customer->salesRep ? ['id' => $customer->salesRep->id, 'name' => $customer->salesRep->name] : null,
                'owned_by_me' => $customer->sales_rep_id === $viewer->id,
                'visible_to_me' => $visible,
            ];
        })->all();
    }

    /**
     * Whether the viewer may create the customer anyway: only when every match is one they may see themselves
     * (a customer already owned by someone outside their reach can never be copied).
     *
     * @param  Collection<int, array{customer: Customer, matched_on: array<int, string>}>  $matches
     */
    public function canOverride(Collection $matches, User $viewer): bool
    {
        return $matches->every(fn (array $match) => $this->scope->canSee($viewer, $match['customer']->sales_rep_id));
    }

    private function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= self::PHONE_TAIL ? substr($digits, -self::PHONE_TAIL) : $digits;
    }
}
