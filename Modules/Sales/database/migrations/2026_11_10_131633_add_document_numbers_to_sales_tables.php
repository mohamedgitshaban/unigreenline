<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\DocumentNumberService;

return new class extends Migration
{
    /**
     * table => [sequence key, prefix]. Orders, invoices, deliveries, collections and returns used to be
     * identified by their ULID only; each now also has a readable number, unique per company.
     */
    private const TABLES = [
        'sales_orders' => ['sales_order', 'SO-'],
        'invoices' => ['invoice', 'INV-'],
        'deliveries' => ['delivery', 'DLV-'],
        'collections' => ['collection', 'COL-'],
        'returns' => ['return', 'RET-'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->string('number', 40)->nullable()->after('id');
                $blueprint->unique(['tenant_id', 'number'], "{$table}_tenant_number_unique");
            });
        }

        $this->numberExistingRows(app(DocumentNumberService::class));
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("{$table}_tenant_number_unique");
                $blueprint->dropColumn('number');
            });
        }
    }

    /**
     * Numbers the rows that already exist, oldest first (ULIDs sort by creation time), and starts each
     * company's counter after them.
     */
    private function numberExistingRows(DocumentNumberService $numbers): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach (self::TABLES as $table => [$key, $prefix]) {
            foreach ($tenantIds as $tenantId) {
                $counter = 0;

                DB::table($table)->where('tenant_id', $tenantId)->orderBy('id')->select('id')
                    ->chunkById(500, function ($rows) use ($table, $prefix, &$counter) {
                        foreach ($rows as $row) {
                            $counter++;

                            DB::table($table)->where('id', $row->id)->update(['number' => $prefix.str_pad((string) $counter, 6, '0', STR_PAD_LEFT)]);
                        }
                    });

                if ($counter > 0) {
                    $numbers->startAt($tenantId, $key, $counter + 1);
                }
            }
        }
    }
};
