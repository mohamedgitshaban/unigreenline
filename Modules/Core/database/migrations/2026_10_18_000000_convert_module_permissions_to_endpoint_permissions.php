<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\LegacyPermissionMap;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions used to be coarse (`expenses.view` = everything view-like in
 * Expenses). Every endpoint now has its own (`expenses.view`,
 * `expense-categories.view`, ...). This converts an existing database: it creates
 * the new permissions, gives each role the per-endpoint permissions that match
 * the coarse ones it held (so nobody gains or loses access), then deletes the
 * coarse permissions. Roles keep working exactly as before; an admin can now
 * narrow them endpoint by endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $legacyPermissions = Permission::query()->get()->filter(fn (Permission $p) => LegacyPermissionMap::isLegacy($p->name));

            $expansions = [];
            foreach (Role::query()->with('permissions')->get() as $role) {
                $held = $role->permissions->pluck('name');
                $legacy = $held->filter(fn (string $name) => LegacyPermissionMap::isLegacy($name))->values()->all();

                if ($legacy !== []) {
                    $expansions[$role->id] = LegacyPermissionMap::expand($legacy);
                }
            }

            foreach (PermissionCatalogue::names() as $name) {
                Permission::findOrCreate($name, 'web');
            }

            foreach ($expansions as $roleId => $names) {
                Role::findById($roleId, 'web')->givePermissionTo($names);
            }

            // A user can also hold a coarse permission directly (not through a role).
            foreach (DB::table('model_has_permissions')->whereIn('permission_id', $legacyPermissions->pluck('id'))->get() as $row) {
                $names = LegacyPermissionMap::expand([$legacyPermissions->firstWhere('id', $row->permission_id)->name]);
                $user = $row->model_type::find($row->model_id);
                $user?->givePermissionTo($names);
            }

            // The names that are both legacy-shaped and still used (`expenses.view`) were
            // just re-created as per-endpoint ones, so only drop rows that are no longer in the catalogue.
            $keep = array_flip(PermissionCatalogue::names());
            Permission::query()->whereIn('id', $legacyPermissions->pluck('id'))->get()
                ->reject(fn (Permission $p) => isset($keep[$p->name]))
                ->each->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // One-way: the coarse permissions are not recreated.
    }
};
