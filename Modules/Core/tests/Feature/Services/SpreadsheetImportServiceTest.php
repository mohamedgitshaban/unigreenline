<?php

namespace Modules\Core\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Exceptions\ImportFailedException;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Services\SpreadsheetImportService;
use Modules\CRM\Imports\CustomerImport;
use Modules\Inventory\Imports\WarehouseImport;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\BuildsWorkbooks;
use Tests\TestCase;

class SpreadsheetImportServiceTest extends TestCase
{
    use BuildsWorkbooks, RefreshDatabase;

    public function test_reports_the_sheet_row_of_a_bad_record_when_the_headings_sit_below_a_title(): void
    {
        $user = User::factory()->recycle(Tenant::factory()->create())->create();
        $file = $this->writeWorkbook(['Warehouses' => [
            ['Warehouse register'],
            [],
            ['Name', 'City', 'Governorate', 'Status'],
            ['Cairo Main', 'Cairo', 'Cairo', 'active'],
            ['Alex Cold', 'Alexandria', 'Alexandria', 'broken'],
        ]]);

        try {
            app(SpreadsheetImportService::class)->run($file, new WarehouseImport($user), $user);
            $this->fail('The bad status should have rejected the file.');
        } catch (ImportFailedException $e) {
            $this->assertSame(5, $e->rowErrors[0]['row']);
            $this->assertArrayHasKey('status', $e->rowErrors[0]['errors']);
        }

        $this->assertSame(0, Warehouse::query()->count());
    }

    public function test_a_needed_marker_counts_as_blank_so_a_required_value_is_still_asked_for(): void
    {
        $user = User::factory()->recycle(Tenant::factory()->create())->create();
        $file = $this->writeWorkbook(['Customer Master Sheet' => [
            ['Customer Code', 'acc.name', 'Customer Category'],
            ['VP-1', 'NEEDED', 'Clinic'],
        ]]);

        try {
            app(SpreadsheetImportService::class)->run($file, new CustomerImport($user), $user);
            $this->fail('A customer without a name should have rejected the file.');
        } catch (ImportFailedException $e) {
            $this->assertSame(2, $e->rowErrors[0]['row']);
            $this->assertArrayHasKey('name', $e->rowErrors[0]['errors']);
        }
    }
}
