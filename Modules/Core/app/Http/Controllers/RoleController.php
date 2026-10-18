<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Exceptions\RecordInUseException;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Http\Requests\AssignRoleUsersRequest;
use Modules\Core\Http\Requests\StoreRoleRequest;
use Modules\Core\Http\Requests\UpdateRoleRequest;
use Modules\Core\Http\Resources\RoleResource;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    /**
     * Administrator is the Gate::before superuser; letting its row be edited
     * or removed would either be a no-op or lock everyone out.
     */
    private const PROTECTED_ROLE = 'Administrator';

    public function index(Request $request)
    {
        $query = Role::query()->select('roles.*')->selectSub($this->usersCountSubquery(), 'users_count')->with('permissions');

        $this->applyFilters($request, $query, ['name']);
        $this->applySorting($request, $query);

        return RoleResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Role $role)
    {
        $this->authorize('view', $role);

        return new RoleResource($this->withUsersCount($role->load('permissions')));
    }

    /**
     * Every assignable permission — one per endpoint action, named
     * `{resource}.{action}` — grouped by module, with the endpoints each one
     * guards. Drives the permission grid on the role form.
     */
    public function permissions(Request $request)
    {
        $catalogue = PermissionCatalogue::all();

        return response()->json([
            // module => [permission names]
            'data' => $catalogue->groupBy('module')->map(fn ($entries) => $entries->pluck('permission')->values())->sortKeys(),
            // module => resource => [permission names]
            'resources' => PermissionCatalogue::grouped(),
            // permission name => ["GET /api/v1/expenses", ...]
            'endpoints' => $catalogue->map(fn (array $entry) => $entry['endpoints']),
        ]);
    }

    public function store(StoreRoleRequest $request)
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data, $request) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);

            $this->recordAudit($request, 'admin', 'Role', $role, 'INSERT', null, [
                'name' => $role->name,
                'permissions' => $data['permissions'] ?? [],
            ]);

            return $role;
        });

        return (new RoleResource($this->withUsersCount($role->load('permissions'))))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        abort_if($role->name === self::PROTECTED_ROLE, 403, 'The Administrator role cannot be modified.');

        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $role) {
            $previous = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];

            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }

            $role->load('permissions');

            $this->recordAudit($request, 'admin', 'Role', $role, 'UPDATE', $previous, [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ]);
        });

        return new RoleResource($this->withUsersCount($role));
    }

    public function destroy(Request $request, Role $role)
    {
        $this->authorize('delete', $role);

        abort_if($role->name === self::PROTECTED_ROLE, 403, 'The Administrator role cannot be deleted.');

        if ($this->usersCount($role) > 0) {
            throw new RecordInUseException('This role is assigned to users. Reassign them before deleting it.');
        }

        DB::transaction(function () use ($request, $role) {
            $snapshot = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()];
            $role->delete();
            $this->recordAudit($request, 'admin', 'Role', $role, 'DELETE', $snapshot, null);
        });

        return response()->noContent();
    }

    /**
     * Puts the given users on this role (replacing whichever role they had).
     */
    public function assignUsers(AssignRoleUsersRequest $request, Role $role)
    {
        $userIds = array_unique($request->validated('user_ids'));

        DB::transaction(function () use ($request, $role, $userIds) {
            User::query()->whereIn('id', $userIds)->get()->each(fn (User $user) => $user->syncRoles([$role]));

            $this->recordAudit($request, 'admin', 'Role', $role, 'UPDATE', null, ['assigned_user_ids' => array_values($userIds)]);
        });

        return new RoleResource($this->withUsersCount($role->load('permissions')));
    }

    /**
     * Counted straight off the pivot table: Role::users() resolves its
     * model through the *current* auth guard, which is `sanctum` here and
     * has no user provider of its own.
     */
    private function usersCountSubquery(): Builder
    {
        return DB::table('model_has_roles')
            ->selectRaw('count(*)')
            ->whereColumn('model_has_roles.role_id', 'roles.id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass());
    }

    private function usersCount(Role $role): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->count();
    }

    private function withUsersCount(Role $role): Role
    {
        return $role->setAttribute('users_count', $this->usersCount($role));
    }
}
