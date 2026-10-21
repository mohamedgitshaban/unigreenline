<?php

namespace Modules\HR\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Resources\HrRecordResource;

/**
 * The five standard endpoints (list, show, create, edit, delete) of a company-scoped record, for the HR
 * features that are plain master data with a few extras (recruitment, fleet). The controller says which model,
 * how to validate it and how it is searched; everything else — the company scope, the audit log, the 404 for another
 * company's record, the 409 for a record in use — is here once.
 */
trait CrudEndpoints
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** The name the record has in the audit log. */
    abstract protected function entity(): string;

    /**
     * The rules for creating a record; for editing they are relaxed to `sometimes` automatically.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(Request $request, ?Model $record): array;

    /** @return array<int, string> */
    protected function searchable(): array
    {
        return [];
    }

    /** @return array<int, string> */
    protected function with(): array
    {
        return [];
    }

    /**
     * Query-string keys that filter on a column of the same name (`?status=a,b`, `?vehicle_id=...`).
     *
     * @return array<int, string>
     */
    protected function filterKeys(): array
    {
        return [];
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scope(Builder $query, Request $request): void {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function onCreate(array $data, Request $request): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function onUpdate(array $data, Model $record, Request $request): array
    {
        return $data;
    }

    protected function afterCreate(Model $record, Request $request): void {}

    protected function afterUpdate(Model $record, Request $request): void {}

    /**
     * Throw to refuse the delete.
     */
    protected function beforeDelete(Model $record): void {}

    /**
     * How the list is ordered when the request does not say (`?sort_by=`): column and direction. Newest first when null.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function defaultOrder(): ?array
    {
        return null;
    }

    protected function exportName(): ?string
    {
        return null;
    }

    /**
     * @return array{0: array<int, string>, 1: Closure(Model): array<int, mixed>}|null
     */
    protected function exportShape(): ?array
    {
        return null;
    }

    public function index(Request $request)
    {
        $model = $this->model();
        $query = $model::query()->where($this->table().'.tenant_id', $request->user()->tenant_id)->with($this->with());

        foreach ($this->filterKeys() as $key) {
            if ($request->filled($key)) {
                $query->whereIn($this->table().".{$key}", array_filter(explode(',', (string) $request->query($key))));
            }
        }

        $this->scope($query, $request);
        $this->applyFilters($request, $query, $this->searchable());

        if (($order = $this->defaultOrder()) && ! $request->filled('sort_by')) {
            $query->orderBy($this->table().'.'.$order[0], $order[1])->orderBy($this->table().'.id');
        } else {
            $this->applySorting($request, $query);
        }

        if (($shape = $this->exportShape()) && ($export = $this->exportIfRequested($request, $query, $shape[0], $shape[1], $this->exportName() ?? $this->table()))) {
            return $export;
        }

        return HrRecordResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new HrRecordResource($this->find($request, $id)->load($this->with()));
    }

    public function store(Request $request)
    {
        $data = $this->onCreate($request->validate($this->rules($request, null)), $request);
        $model = $this->model();
        $record = new $model($data + ['tenant_id' => $request->user()->tenant_id] + (in_array('created_by', (new $model)->getFillable(), true) ? ['created_by' => $request->user()->id] : []));
        $record->save();

        $this->recordAudit($request, 'hr', $this->entity(), $record, 'INSERT', null, array_intersect_key($record->getAttributes(), array_flip(array_slice(array_keys($data), 0, 8))));
        $this->afterCreate($record, $request);

        return (new HrRecordResource($record->refresh()->load($this->with())))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $record = $this->find($request, $id);
        $data = $this->onUpdate($request->validate($this->relax($this->rules($request, $record))), $record, $request);

        $this->updateRecord($request, $record, $data, 'hr', $this->entity());
        $this->afterUpdate($record, $request);

        return new HrRecordResource($record->refresh()->load($this->with()));
    }

    public function destroy(Request $request, string $id)
    {
        return $this->destroyRecord($request, $this->find($request, $id), 'hr', $this->entity(), fn (Model $record) => $this->beforeDelete($record));
    }

    protected function find(Request $request, string $id): Model
    {
        $model = $this->model();

        return $model::query()->where($this->table().'.tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    protected function table(): string
    {
        $model = $this->model();

        return (new $model)->getTable();
    }

    /**
     * `exists:` for an id that must belong to this company.
     */
    protected function owned(Request $request, string $table): mixed
    {
        return Rule::exists($table, 'id')->where('tenant_id', $request->user()->tenant_id);
    }

    /**
     * Turns the creation rules into editing rules: nothing is required, only what is sent is checked.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function relax(array $rules): array
    {
        return array_map(function ($rule) {
            $rule = is_array($rule) ? $rule : explode('|', (string) $rule);
            $rule = array_values(array_filter($rule, fn ($r) => $r !== 'required'));

            return in_array('sometimes', $rule, true) ? $rule : ['sometimes', ...$rule];
        }, $rules);
    }
}
