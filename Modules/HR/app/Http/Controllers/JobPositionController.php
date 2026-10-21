<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Models\JobPosition;

/**
 * The jobs the company is hiring for. Each carries how many people are wanted and how many have been hired; when the
 * vacancies are filled the job closes.
 */
class JobPositionController extends Controller
{
    use CrudEndpoints;

    protected function model(): string
    {
        return JobPosition::class;
    }

    protected function entity(): string
    {
        return 'JobPosition';
    }

    protected function searchable(): array
    {
        return ['name', 'location', 'description'];
    }

    protected function with(): array
    {
        return ['department:id,name', 'recruiter:id,name'];
    }

    protected function filterKeys(): array
    {
        return ['status', 'department_id', 'recruiter_id', 'employment_type'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->withCount(['applicants', 'applicants as active_applicants_count' => fn ($q) => $q->where('status', 'active')]);
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'string', $this->owned($request, 'hr_departments')],
            'recruiter_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'vacancies' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'closed'])],
            'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'location' => ['nullable', 'string', 'max:255'],
            'salary_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'gte:salary_min'],
            'description' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'deadline' => ['nullable', 'date'],
        ];
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->applicants()->exists()) {
            throw new HrRuleException('This job has applicants. Close it instead of deleting it.');
        }
    }

    protected function exportShape(): array
    {
        return [['Name', 'Department', 'Status', 'Vacancies', 'Hired', 'Type', 'Location'], fn (JobPosition $j) => [$j->name, $j->department?->name, $j->status, $j->vacancies, $j->hired_count, $j->employment_type, $j->location]];
    }
}
