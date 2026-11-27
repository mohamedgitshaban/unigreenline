<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\After;

/**
 * Writes a real .xlsx file for a test to import, laid out cell for cell the way the test says
 * (a title above the headings, formulas, blank filler rows ...), and deletes it afterwards.
 * Also turns a downloaded import template back into an upload.
 */
trait BuildsWorkbooks
{
    /** @var list<string> */
    private array $workbookPaths = [];

    /**
     * @param  array<string, list<list<mixed>>>  $sheets  Sheet name => rows from the top-left cell; a null cell stays blank.
     */
    protected function writeWorkbook(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        $path = sys_get_temp_dir().'/'.uniqid('workbook_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $this->workbookPaths[] = $path;
    }

    /**
     * The file an import-template endpoint sent, ready to be posted back to the import endpoint as it is.
     */
    protected function templateAsUpload(TestResponse $download): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('template.xlsx', file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    #[After]
    protected function deleteWorkbooks(): void
    {
        foreach ($this->workbookPaths as $path) {
            @unlink($path);
        }

        $this->workbookPaths = [];
    }
}
