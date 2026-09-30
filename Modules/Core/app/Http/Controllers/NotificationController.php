<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Http\Resources\NotificationResource;
use Modules\Core\Models\Notification;

class NotificationController extends Controller
{
    use Sortable;

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Notification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereNull('user_id'));

        $this->applySorting($request, $query);

        $notifications = $query->paginate($request->integer('per_page', 15));

        return NotificationResource::collection($notifications);
    }

    /**
     * `unread` is a single flag per row, not per-viewer (spec §4.14 has no
     * per-user read-state table) — marking a broadcast notification (user_id
     * null) read here hides it for every user, not just the caller. This is
     * a schema limitation, not a bug in this endpoint; see docs/api for the
     * documented trade-off.
     */
    public function readAll(Request $request)
    {
        $user = $request->user();

        Notification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
            ->where('unread', true)
            ->update(['unread' => false]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
