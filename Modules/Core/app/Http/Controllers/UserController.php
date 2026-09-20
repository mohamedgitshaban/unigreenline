<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Http\Requests\StoreUserRequest;
use Modules\Core\Http\Requests\UpdateUserRequest;
use Modules\Core\Http\Resources\UserResource;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('role'), fn ($query) => $query->role($request->query('role')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        $user->setAttribute('warehouse_ids', $this->warehouseIds($user));

        return new UserResource($user);
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'] ?? 'active',
        ]);

        $user->assignRole($data['role']);
        $this->syncWarehouses($user, $data['warehouse_ids'] ?? []);

        $this->auditLog->record([
            'module' => 'admin',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'operation' => 'INSERT',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'new_values' => ['name' => $user->name, 'email' => $user->email, 'role' => $data['role'], 'status' => $user->status],
            'ip_address' => $request->ip(),
        ]);

        $user->setAttribute('warehouse_ids', $this->warehouseIds($user));

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        $prevValues = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'status' => $user->status,
        ];

        $user->fill(collect($data)->except(['role', 'warehouse_ids'])->all());
        $user->save();

        if (array_key_exists('role', $data)) {
            $user->syncRoles([$data['role']]);
        }

        if (array_key_exists('warehouse_ids', $data)) {
            $this->syncWarehouses($user, $data['warehouse_ids']);
        }

        $this->auditLog->record([
            'module' => 'admin',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'operation' => 'UPDATE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'prev_values' => $prevValues,
            'new_values' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'status' => $user->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        $user->setAttribute('warehouse_ids', $this->warehouseIds($user));

        return new UserResource($user);
    }

    /**
     * @return array<int, string>
     */
    private function warehouseIds(User $user): array
    {
        return DB::table('user_warehouses')->where('user_id', $user->id)->pluck('warehouse_id')->all();
    }

    /**
     * @param  array<int, string>  $warehouseIds
     */
    private function syncWarehouses(User $user, array $warehouseIds): void
    {
        DB::transaction(function () use ($user, $warehouseIds) {
            DB::table('user_warehouses')->where('user_id', $user->id)->delete();

            if ($warehouseIds !== []) {
                DB::table('user_warehouses')->insert(array_map(
                    fn ($warehouseId) => ['user_id' => $user->id, 'warehouse_id' => $warehouseId],
                    array_unique($warehouseIds)
                ));
            }
        });
    }
}
