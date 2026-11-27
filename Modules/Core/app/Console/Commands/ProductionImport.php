<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Exceptions\ImportFailedException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use Modules\Core\Services\SpreadsheetImportService;
use Modules\CRM\Imports\CustomerImport;
use Modules\Inventory\Imports\ProductImport;
use Modules\Inventory\Imports\WarehouseImport;
use Modules\Purchasing\Imports\SupplierImport;
use Modules\Sales\Imports\InvoiceImport;
use Throwable;

/**
 * Loads the company's workbook (customer, product and warehouse masters, the invoice sheet, the suppliers and
 * factories named in the production sheet) through the same importers the upload buttons use, in the order
 * they depend on each other. All or nothing: if any sheet has an invalid row, nothing is saved and the rows
 * to fix are listed.
 */
class ProductionImport extends Command
{
    /**
     * Import order: customers and products before the invoices and returns that refer to them.
     *
     * @var array<string, class-string<ImportDefinition>>
     */
    private const ENTITIES = [
        'warehouses' => WarehouseImport::class,
        'suppliers' => SupplierImport::class,
        'products' => ProductImport::class,
        'customers' => CustomerImport::class,
        'invoices' => InvoiceImport::class,
    ];

    /** How many failing rows are listed before the rest are only counted. */
    private const ERROR_LINES = 50;

    protected $signature = 'production:import
        {file : Path of the workbook (.xlsx)}
        {--user= : Email of the account the data is imported under (default: the only account)}
        {--only=* : Import only these (warehouses, suppliers, products, customers, invoices); repeat for several}
        {--update : Update records that already exist instead of skipping them}
        {--dry-run : Read and check everything, then undo it}';

    protected $description = 'Load the company workbook (customers, products, warehouses, suppliers, invoices) into the database';

    public function handle(SpreadsheetImportService $importer): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read the file {$file}.");

            return self::FAILURE;
        }

        $only = $this->option('only') ?: array_keys(self::ENTITIES);
        $unknown = array_diff($only, array_keys(self::ENTITIES));

        if ($unknown !== []) {
            $this->error('Unknown --only value(s): '.implode(', ', $unknown).'. Choose from '.implode(', ', array_keys(self::ENTITIES)).'.');

            return self::FAILURE;
        }

        $user = $this->actingUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $results = [];
        $entity = '';

        DB::beginTransaction();

        try {
            foreach (array_intersect(array_keys(self::ENTITIES), $only) as $entity) {
                $definitionClass = self::ENTITIES[$entity];
                $counts = $importer->run($file, new $definitionClass($user), $user, (bool) $this->option('update'));
                $results[] = [$entity, $counts['created'], $counts['updated'], $counts['skipped']];
            }
        } catch (ImportFailedException $e) {
            DB::rollBack();
            $this->reportFailure($entity, $e);

            return self::FAILURE;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("Nothing was imported. While importing {$entity}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(['Imported', 'Created', 'Updated', 'Skipped'], $results);

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Dry run: everything above was checked and then undone. Nothing was saved.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Imported and saved.');

        return self::SUCCESS;
    }

    /**
     * Says which sheet failed and lists every row to fix, one per line, so the whole file can be corrected in one go.
     */
    private function reportFailure(string $entity, ImportFailedException $e): void
    {
        if ($e->rowErrors === []) {
            $this->error("Nothing was imported. The {$entity} sheet could not be used: {$e->getMessage()}");

            return;
        }

        $this->error('Nothing was imported. The '.$entity.' sheet has '.count($e->rowErrors).' row(s) to fix:');

        foreach (array_slice($e->rowErrors, 0, self::ERROR_LINES) as $error) {
            foreach ($error['errors'] as $column => $messages) {
                foreach ($messages as $message) {
                    $this->line("  Row {$error['row']} ({$column}): {$message}");
                }
            }
        }

        if (count($e->rowErrors) > self::ERROR_LINES) {
            $this->line('  ...and '.(count($e->rowErrors) - self::ERROR_LINES).' more row(s).');
        }
    }

    private function actingUser(): ?User
    {
        $email = $this->option('user');
        $users = User::query()->when($email, fn ($query) => $query->where('email', $email))->limit(2)->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        $this->error($users->isEmpty()
            ? ($email ? "No account with the email {$email}." : 'There is no account yet. Run production:reset first.')
            : 'There is more than one account; say which one to import under with --user=email.');

        return null;
    }
}
