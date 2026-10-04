<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Http\Resources\AuditLogResource;
use Modules\Core\Models\AuditLog;
use Modules\Core\Services\AuditLogService;

class AuditLogController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('admin.audit');

        $query = AuditLog::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->query('module')))
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->query('entity_type')));

        $query->with([
            'user',
            'user.roles.permissions',
            'user.permissions',
        ]);

        $this->applyFilters($request, $query, ['user_name', 'module', 'entity_type', 'entity_id', 'operation', 'ip_address', 'request_id']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'admin.export', $query,
            ['ID', 'Occurred At', 'User', 'Module', 'Entity Type', 'Entity ID', 'Operation', 'IP Address', 'Prev Hash', 'Entry Hash'],
            fn (AuditLog $a) => [$a->id, $a->occurred_at->toDateTimeString(), $a->user_name, $a->module, $a->entity_type, $a->entity_id, $a->operation, $a->ip_address, $a->prev_hash, $a->entry_hash],
            'audit-log',
        )) {
            return $export;
        }

        $entries = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return AuditLogResource::collection($entries);
    }

    /**
     * Spec §5.7.5: re-walk the chain and report the first row where
     * tampering broke it, if any. `audit:verify` (Step 1) is the console
     * equivalent of this for ops/cron use; this is the admin-UI action.
     */
    public function verifyIntegrity(Request $request)
    {
        $this->authorize('admin.audit');

        $brokenAt = $this->auditLog->verifyChain();

        return response()->json([
            'intact' => $brokenAt === null,
            'broken_at' => $brokenAt,
        ]);
    }
}
