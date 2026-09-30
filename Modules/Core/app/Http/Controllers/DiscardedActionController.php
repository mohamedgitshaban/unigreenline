<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Requests\StoreDiscardedActionRequest;
use Modules\Core\Http\Resources\DiscardedActionResource;
use Modules\Core\Models\DiscardedAction;

class DiscardedActionController extends Controller
{
    use Exportable;

    public function index(Request $request)
    {
        $this->authorize('admin.view');

        $query = DiscardedAction::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('created_at');

        if ($export = $this->exportIfRequested(
            $request, 'admin.export', $query,
            ['ID', 'User', 'Type', 'Label', 'Payload', 'Created At'],
            fn (DiscardedAction $d) => [$d->id, $d->user_name, $d->type, $d->label, json_encode($d->payload), $d->created_at->toDateTimeString()],
            'discarded-actions',
        )) {
            return $export;
        }

        $actions = $query->paginate($request->integer('per_page', 15));

        return DiscardedActionResource::collection($actions);
    }

    public function store(StoreDiscardedActionRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        $action = DiscardedAction::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'type' => $data['type'],
            'label' => $data['label'],
            'payload' => $data['payload'],
        ]);

        return new DiscardedActionResource($action);
    }
}
