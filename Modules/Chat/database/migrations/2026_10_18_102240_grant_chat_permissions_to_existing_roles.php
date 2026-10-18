<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Chat is for everyone: RolePermissionSeeder gives the chat permissions to
 * every role it creates, and this does the same for the roles that already
 * exist in a deployed database (re-running the seeder would reset them all to
 * its defaults). Administrators can still take chat away from a role afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = PermissionCatalogue::all()->where('module', 'chat')->keys()->all();

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::query()->where('guard_name', 'web')->get()->each->givePermissionTo($names);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The permissions stay; revoking them from roles is the admin's call.
    }
};
