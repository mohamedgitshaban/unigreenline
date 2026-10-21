<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Http\Resources\NotificationResource;
use Modules\Core\Models\Notification;

class NotificationController extends Controller
{
    use Filterable, Sortable;

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Notification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereNull('user_id'));

        $query->when($request->filled('unread'), fn (Builder $q) => $q->where('unread', $request->boolean('unread')))
            ->when($request->filled('module'), fn (Builder $q) => $q->where('link_module', $request->query('module')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->query('type')));

        $this->applyFilters($request, $query, ['title', 'body']);

        $this->applySorting($request, $query);

        $notifications = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * How many notifications are unread, in total and per module (GET /notifications/unread-count).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        $perModule = Notification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
            ->where('unread', true)
            ->selectRaw('link_module, count(*) as total')
            ->groupBy('link_module')
            ->pluck('total', 'link_module');

        return response()->json(['data' => ['total' => (int) $perModule->sum(), 'by_module' => $perModule->map(fn ($n) => (int) $n)]]);
    }

    /**
     * Marks one of the caller's own notifications read (PUT /notifications/{id}/read). A broadcast
     * (no user_id) is shared state and is left to read-all.
     */
    public function read(Request $request, string $id)
    {
        $notification = Notification::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update(['unread' => false]);

        return new NotificationResource($notification);
    }

    /**
     * Same visibility as the list: the caller's own notifications plus
     * tenant-wide broadcasts (no user_id). Anything else is a 404.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $notification = Notification::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
            ->findOrFail($id);

        return new NotificationResource($notification);
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
