<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;

/**
 * The reporting line: who reports to whom. `GET /team` is the caller's own team (open to every
 * signed-in user, like `/auth/me`); `GET /users/{id}/team` is anyone's, for users who may see that person.
 */
class TeamController extends Controller
{
    public function __construct(private readonly DataScope $scope) {}

    public function mine(Request $request)
    {
        return $this->team($request->user(), $request->user());
    }

    public function of(Request $request, User $user)
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($this->scope->canSee($request->user(), $user->id), 403);

        return $this->team($request->user(), $user);
    }

    private function team(User $viewer, User $root)
    {
        $tree = User::query()->where('tenant_id', $root->tenant_id)->with('roles:id,name')->get(['id', 'name', 'email', 'status', 'manager_id'])->keyBy('id');
        $members = [];
        $frontier = [$root->id];
        $depth = 0;

        while ($frontier !== [] && $depth < 20) {
            $depth++;
            $next = [];

            foreach ($tree as $user) {
                if ($user->manager_id !== null && in_array($user->manager_id, $frontier, true) && $user->id !== $root->id && ! isset($members[$user->id])) {
                    $members[$user->id] = [
                        'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->roles->first()?->name,
                        'status' => $user->status, 'manager_id' => $user->manager_id, 'depth' => $depth,
                    ];
                    $next[] = $user->id;
                }
            }

            $frontier = $next;
        }

        $manager = $root->manager_id ? $tree->get($root->manager_id) : null;

        return response()->json(['data' => [
            'user' => ['id' => $root->id, 'name' => $root->name],
            'manager' => $manager ? ['id' => $manager->id, 'name' => $manager->name] : null,
            'data_scope' => $this->scope->effective($root),
            'direct_reports' => collect($members)->where('depth', 1)->count(),
            'members' => array_values($members),
        ]]);
    }
}
