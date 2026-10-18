<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Chat\Http\Resources\ChatUserResource;
use Modules\Core\Models\User;

class ChatUserController extends Controller
{
    /**
     * The colleagues the caller can start a conversation with: active users
     * of the same tenant, by name. Deliberately not the generic `?filter[...]`
     * list — that would let anyone filter on emails and login history.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $users = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->whereKeyNot($user->id)
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ChatUserResource::collection($users);
    }
}
