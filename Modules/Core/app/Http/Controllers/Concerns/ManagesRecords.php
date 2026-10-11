<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Core\Exceptions\RecordInUseException;
use Modules\Core\Services\AuditLogService;

/**
 * Shared update/delete plumbing for plain master-data controllers: tenant
 * scoping (404 for another tenant's row, checked before the policy so
 * Administrator's Gate::before bypass can't cross tenants), the `delete` /
 * `update` policy ability, an audit-log row, and a 409 instead of a 500 when
 * a foreign key says the row is still referenced.
 */
trait ManagesRecords
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(Model): void|null  $afterSave  Runs inside the transaction once the row is saved.
     */
    protected function updateRecord(Request $request, Model $model, array $data, string $module, string $entityType, ?Closure $afterSave = null): Model
    {
        $this->assertSameTenant($request, $model);

        DB::transaction(function () use ($request, $model, $data, $module, $entityType, $afterSave) {
            $model->fill($data);
            $dirty = $model->getDirty();
            $previous = array_intersect_key($model->getOriginal(), $dirty);
            $model->save();

            if ($afterSave) {
                $afterSave($model);
            }

            if ($dirty !== []) {
                $this->recordAudit($request, $module, $entityType, $model, 'UPDATE', $previous, $dirty);
            }
        });

        return $model;
    }

    /**
     * @param  Closure(Model): void|null  $beforeDelete  May throw to veto the delete.
     */
    protected function destroyRecord(Request $request, Model $model, string $module, string $entityType, ?Closure $beforeDelete = null): Response
    {
        $this->assertSameTenant($request, $model);

        $this->authorize('delete', $model);

        try {
            DB::transaction(function () use ($request, $model, $module, $entityType, $beforeDelete) {
                if ($beforeDelete) {
                    $beforeDelete($model);
                }

                $snapshot = $model->attributesToArray();
                $model->delete();

                $this->recordAudit($request, $module, $entityType, $model, 'DELETE', $snapshot, null);
            });
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation (restrict-on-delete).
            if ($e->getCode() === '23000') {
                throw new RecordInUseException("This {$entityType} is referenced by other records and cannot be deleted.");
            }

            throw $e;
        }

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    protected function recordAudit(Request $request, string $module, string $entityType, Model $model, string $operation, ?array $previous, ?array $new): void
    {
        app(AuditLogService::class)->record([
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $model->getKey(),
            'operation' => $operation,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }

    private function assertSameTenant(Request $request, Model $model): void
    {
        abort_unless($model->getAttribute('tenant_id') === $request->user()->tenant_id, 404);
    }
}
