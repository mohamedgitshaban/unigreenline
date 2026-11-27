<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\AccountSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionCatalogue;
use Modules\Expenses\Database\Seeders\ExpenseCategorySeeder;
use Modules\Inventory\Database\Seeders\UnitSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Starts the database over for go-live: every row is deleted and the system is left with one company, one
 * role holding every permission, and one account with that role. The chart of accounts, the packaging units
 * and the expense categories are put back afterwards, because invoices, products and expenses can't be
 * recorded without them; everything else (customers, products, stock, orders, users, roles ...) is gone.
 *
 * Meant to be followed by `production:import` to load the company's workbook.
 */
class ProductionReset extends Command
{
    /** Never emptied: it records which migrations ran, and without it the schema can't be managed. */
    private const KEPT_TABLE = 'migrations';

    protected $signature = 'production:reset
        {--name=Administrator : Name of the one account}
        {--email= : Email of the one account (asked for when omitted)}
        {--password= : Password of the account (generated, and shown once, when omitted)}
        {--role=Administrator : Name of the one role}
        {--tenant= : Slug of the company to keep (default: the first one)}
        {--company=Uni Green Line : Name of the company to create when there is none}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete ALL data and leave one account with one role holding every permission';

    public function handle(): int
    {
        $email = $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('Email address of the one account') : null);

        $validator = Validator::make(
            ['email' => $email, 'name' => $this->option('name'), 'role' => $this->option('role')],
            ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'role' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $slug = $this->option('tenant');
        $kept = $slug ? Tenant::query()->where('slug', $slug)->first() : Tenant::query()->orderBy('id')->first();

        if ($slug && $kept === null) {
            $this->error("No company with the slug '{$slug}'. Nothing was deleted.");

            return self::FAILURE;
        }

        $tables = array_values(array_diff(Schema::getTableListing(DB::getDatabaseName(), false), [self::KEPT_TABLE]));
        $counts = array_filter(array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables)));

        $this->table(['Table', 'Rows to delete'], collect($counts)->map(fn (int $rows, string $table): array => [$table, $rows])->values()->all());

        if (! $this->option('force') && ! $this->confirmed()) {
            $this->warn('Cancelled. Nothing was deleted.');

            return self::FAILURE;
        }

        $this->wipe($tables, $kept);

        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $tenant = $kept ?? Tenant::create(['name' => $this->option('company'), 'slug' => Str::slug($this->option('company')), 'plan' => 'enterprise', 'active' => true]);
        $user = $this->provision($tenant, $this->option('name'), $email, $password, $this->option('role'));

        $this->info('Done. The database now holds one company ('.$tenant->name.') and one account:');
        $this->table(
            ['Name', 'Email', 'Role', 'Password'],
            [[$user->name, $user->email, $this->option('role'), $this->option('password') ? '(the one you gave)' : $password]],
        );

        if (! $this->option('password')) {
            $this->warn('This password is shown only now. Change it after the first sign-in.');
        }

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        $database = DB::getDatabaseName();
        $this->warn('This permanently deletes every record in the '.app()->environment()." '{$database}' database.");

        return $this->ask("Type the database name ({$database}) to continue") === $database;
    }

    /**
     * Empties every table (one company may stay, since the account has to belong to something) inside one
     * transaction, so a failure part-way leaves the data as it was.
     *
     * @param  list<string>  $tables
     */
    private function wipe(array $tables, ?Tenant $kept): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($tables, $kept): void {
            DB::transaction(function () use ($tables, $kept): void {
                foreach ($tables as $table) {
                    $query = DB::table($table);

                    if ($table === 'tenants' && $kept !== null) {
                        $query->where('id', '!=', $kept->id);
                    }

                    $query->delete();
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The one role (every permission there is), its one account, and the reference data the system can't run without.
     */
    private function provision(Tenant $tenant, string $name, string $email, string $password, string $roleName): User
    {
        foreach (PermissionCatalogue::names() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate($roleName, 'web')->syncPermissions(PermissionCatalogue::names());

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'avatar_initials' => mb_strtoupper(mb_substr($name, 0, 2)),
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($roleName);

        (new AccountSeeder)->seedTenant($tenant);
        (new ExpenseCategorySeeder)->seedTenant($tenant);
        (new UnitSeeder)->run();

        return $user;
    }
}
