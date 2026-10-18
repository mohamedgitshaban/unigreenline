<?php

namespace Modules\HR\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\HR\Models\Employee;

/**
 * Self-service. Someone who may list employees (`employees.view`) sees every
 * employee's attendance, leave and so on. Anyone else — an ordinary employee
 * who was only given check-in and "my requests" permissions — sees and acts on
 * their own records only, found through the employee record linked to their login.
 */
trait ScopesToOwnEmployee
{
    protected function seesEveryone(Request $request): bool
    {
        return $request->user()->can('employees.view');
    }

    protected function ownEmployee(Request $request): ?Employee
    {
        return Employee::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function scopeToOwn(Request $request, Builder $query, string $column = 'employee_id'): Builder
    {
        if ($this->seesEveryone($request)) {
            return $query;
        }

        return $query->where($query->qualifyColumn($column), $this->ownEmployee($request)?->id ?? '-');
    }

    /**
     * 404 (not 403, so existence isn't leaked) when a self-service user reaches for someone else's record.
     */
    protected function assertCanSee(Request $request, string $employeeId): void
    {
        abort_unless($this->seesEveryone($request) || $this->ownEmployee($request)?->id === $employeeId, 404);
    }

    /**
     * The employee a new request is for: whoever HR named, or — for a
     * self-service user — themselves, whatever they sent.
     */
    protected function employeeIdFor(Request $request, ?string $requested): string
    {
        if ($this->seesEveryone($request)) {
            if ($requested === null) {
                throw ValidationException::withMessages(['employee_id' => 'The employee field is required.']);
            }

            return $requested;
        }

        $own = $this->ownEmployee($request);

        if ($own === null) {
            throw ValidationException::withMessages(['employee_id' => 'Your login is not linked to an employee record. Ask HR to link it.']);
        }

        return $own->id;
    }
}
