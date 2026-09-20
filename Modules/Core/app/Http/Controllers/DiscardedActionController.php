<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Requests\StoreDiscardedActionRequest;
use Modules\Core\Http\Resources\DiscardedActionResource;
use Modules\Core\Models\DiscardedAction;

class DiscardedActionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('admin.view');

        $actions = DiscardedAction::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

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
