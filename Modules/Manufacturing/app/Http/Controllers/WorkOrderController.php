<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Resources\WorkOrderResource;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Services\WorkOrderService;

class WorkOrderController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['productionOrder', 'workCenter', 'assignee', 'timeLogs', 'qualityChecks'];

    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function index(Request $request)
    {
        $query = WorkOrder::query()->where('tenant_id', $request->user()->tenant_id)->with(['productionOrder', 'workCenter', 'assignee']);

        foreach (['status', 'work_center_id', 'production_order_id', 'assigned_to'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('scheduled_start', '>=', $request->input('from')));
        $query->when($request->filled('to'), fn ($q) => $q->whereDate('scheduled_start', '<=', $request->input('to')));

        $this->applyFilters($request, $query, ['number', 'name', 'workCenter.name', 'productionOrder.number']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Number', 'Order', 'Operation', 'Work Center', 'Status', 'Start', 'End', 'Planned (min)', 'Actual (min)', 'Processed', 'Rejected'],
            fn (WorkOrder $w) => [$w->number, $w->productionOrder?->number, $w->name, $w->workCenter?->name, $w->status, $w->scheduled_start, $w->scheduled_end, $w->planned_minutes, $w->actual_minutes, $w->qty_processed, $w->qty_rejected],
            'work-orders',
        )) {
            return $export;
        }

        return WorkOrderResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        return new WorkOrderResource($workOrder->load(self::RELATIONS));
    }

    public function start(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        return $this->resource($this->workOrders->start($workOrder, $this->actor($request)));
    }

    public function pause(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->resource($this->workOrders->pause($workOrder, $this->actor($request), $data['reason'] ?? null));
    }

    public function resume(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        return $this->resource($this->workOrders->resume($workOrder, $this->actor($request)));
    }

    public function complete(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        $data = $request->validate([
            'qty_processed' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'qty_rejected' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'failure_reason' => ['nullable', 'string', 'max:1000'],
            'reject_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->resource($this->workOrders->complete($workOrder, $data, $this->actor($request)));
    }

    /**
     * Moves the operation to a new start. The requested time must be free (and working time) unless `auto_shift` is true.
     */
    public function reschedule(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        $data = $request->validate(['scheduled_start' => ['required', 'date'], 'auto_shift' => ['sometimes', 'boolean']]);

        return $this->resource($this->workOrders->reschedule($workOrder, $data, $this->actor($request)));
    }

    public function assign(Request $request, WorkOrder $workOrder)
    {
        $this->owned($request, $workOrder);

        $data = $request->validate(['user_id' => ['nullable', 'string', \Illuminate\Validation\Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)]]);

        return $this->resource($this->workOrders->assign($workOrder, $data['user_id'] ?? null, $this->actor($request)));
    }

    private function owned(Request $request, WorkOrder $workOrder): void
    {
        abort_unless($workOrder->tenant_id === $request->user()->tenant_id, 404);
    }

    private function resource(WorkOrder $workOrder): WorkOrderResource
    {
        return new WorkOrderResource($workOrder->load(self::RELATIONS));
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}
