<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\AuditLogResource;
use Modules\Core\Models\AuditLog;
use Modules\Core\Services\AuditLogService;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('admin.audit');

        $entries = AuditLog::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->query('module')))
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->query('entity_type')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

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
