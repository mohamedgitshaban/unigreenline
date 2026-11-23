<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

/**
 * Empties the database down to the Super Admin / Administrator users, so the system can be
 * handed over (or re-tested) without any business data in it.
 *
 * What survives: the users holding one of the kept roles, their role assignments and API
 * tokens, the roles and permissions themselves (without them the admins could not do anything),
 * the tenants, and the migrations table. Every other table is emptied.
 *
 * The delete runs in one transaction, so a failure part-way leaves the data as it was.
 */
class WipeData extends Command
{
    /** @var list<string> */
    private const DEFAULT_ROLES = ['Super Admin', 'Administrator'];

    /** Tables that are never emptied: schema history, and the access setup the kept users depend on. */
    private const PROTECTED_TABLES = ['migrations', 'roles', 'permissions', 'role_has_permissions', 'tenants'];

    /**
     * Tables whose rows belong to users, mapped to their owner id column and (for morph columns)
     * owner type column. Only the kept users' rows survive.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const USER_OWNED_TABLES = [
        'users' => ['id', null],
        'model_has_roles' => ['model_id', 'model_type'],
        'model_has_permissions' => ['model_id', 'model_type'],
        'personal_access_tokens' => ['tokenable_id', 'tokenable_type'],
    ];

    protected $signature = 'data:wipe
        {--role=* : Role whose users are kept; repeat for several (default: Super Admin and Administrator)}
        {--keep=* : Extra table to leave untouched; repeat for several (for example chart_of_accounts)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete all data except the Super Admin / Administrator users (and the roles, permissions and tenants they need)';

    public function handle(): int
    {
        /** @var list<string> $roles */
        $roles = $this->option('role') ?: self::DEFAULT_ROLES;

        $keptUsers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->with('roles')
            ->get();

        if ($keptUsers->isEmpty()) {
            $this->error('No user holds the role '.implode(' or ', $roles).'; refusing to wipe everything.');

            return self::FAILURE;
        }

        $keptIds = $keptUsers->modelKeys();

        // Scoped to this database: unscoped, MySQL lists the tables of every database on the server.
        $tables = Schema::getTableListing(DB::getDatabaseName(), false);
        $unknownTables = array_diff($this->option('keep'), $tables);

        if ($unknownTables !== []) {
            $this->error('Unknown table(s) in --keep: '.implode(', ', $unknownTables).'. Nothing was deleted.');

            return self::FAILURE;
        }

        $untouched = array_merge(self::PROTECTED_TABLES, $this->option('keep'));
        $wipeTables = array_values(array_diff($tables, $untouched));

        $counts = [];
        foreach ($wipeTables as $table) {
            $counts[$table] = $this->rowsToDelete($table, $keptIds)->count();
        }
        $counts = array_filter($counts);

        if ($counts === []) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Rows to delete'],
            collect($counts)->map(fn (int $rows, string $table): array => [$table, $rows])->values()->all()
        );
        $this->table(
            ['Kept user', 'Email', 'Roles'],
            $keptUsers->map(fn (User $user): array => [$user->name, $user->email, $user->roles->pluck('name')->implode(', ')])->all()
        );

        if (! $this->option('force') && ! $this->confirm($this->confirmationMessage(array_sum($counts)), false)) {
            $this->warn('Cancelled. Nothing was deleted.');

            return self::FAILURE;
        }

        $deleted = $this->wipe(array_keys($counts), $keptIds);

        $this->info("Deleted {$deleted} row(s) from ".count($counts).' table(s); kept '.count($keptIds).' user(s).');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $keptIds
     */
    private function wipe(array $tables, array $keptIds): int
    {
        $deleted = 0;

        Schema::withoutForeignKeyConstraints(function () use ($tables, $keptIds, &$deleted): void {
            DB::transaction(function () use ($tables, $keptIds, &$deleted): void {
                foreach ($tables as $table) {
                    $deleted += $this->rowsToDelete($table, $keptIds)->delete();
                }

                // A kept admin may report to someone who was just deleted.
                DB::table('users')->whereNotIn('manager_id', $keptIds)->update(['manager_id' => null]);
            });
        });

        return $deleted;
    }

    /**
     * @param  list<string>  $keptIds
     */
    private function rowsToDelete(string $table, array $keptIds): Builder
    {
        $query = DB::table($table);

        if (! isset(self::USER_OWNED_TABLES[$table])) {
            return $query;
        }

        [$idColumn, $typeColumn] = self::USER_OWNED_TABLES[$table];

        return $query->where(function (Builder $query) use ($idColumn, $typeColumn, $keptIds): void {
            $query->whereNotIn($idColumn, $keptIds);

            if ($typeColumn !== null) {
                $query->orWhere($typeColumn, '!=', (new User)->getMorphClass());
            }
        });
    }

    private function confirmationMessage(int $rows): string
    {
        $environment = app()->environment();

        return "Permanently delete {$rows} row(s) from the '".DB::getDatabaseName()."' database ({$environment})? This cannot be undone.";
    }
}
