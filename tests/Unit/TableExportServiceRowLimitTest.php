<?php

namespace Tests\Unit;

use App\Exceptions\ExportTooLargeException;
use App\Services\TableExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TableExportService is the single place every screen's export routes
 * through, so the row-count safeguard (added after an intermittent
 * production nginx/PHP-FPM "no response" error was traced to unbounded
 * PDF/Excel exports being able to run long enough to tie up a worker)
 * lives — and is tested — here rather than duplicated per screen.
 */
class TableExportServiceRowLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function bigRows(int $count): array
    {
        return array_fill(0, $count, ['A', 'B', 'C']);
    }

    /** Invokes the protected limit check directly — avoids actually rendering thousands of PDF rows through dompdf just to prove the boundary is exact. */
    protected function assertWithinLimit(TableExportService $service, int $rowCount): void
    {
        $reflection = new \ReflectionMethod($service, 'assertWithinLimit');
        $reflection->setAccessible(true);
        $reflection->invoke($service, $rowCount);
    }

    public function test_a_row_count_of_exactly_the_limit_does_not_throw(): void
    {
        $this->assertWithinLimit(new TableExportService, TableExportService::MAX_ROWS);
        $this->addToAssertionCount(1);
    }

    public function test_a_row_count_one_over_the_limit_throws(): void
    {
        $this->expectException(ExportTooLargeException::class);

        $this->assertWithinLimit(new TableExportService, TableExportService::MAX_ROWS + 1);
    }

    public function test_a_small_pdf_export_within_the_limit_succeeds(): void
    {
        $service = new TableExportService;

        $response = $service->toPdf('Title', 'Shop', 'All records', ['A', 'B', 'C'], $this->bigRows(5));

        $this->assertNotNull($response);
    }

    public function test_a_pdf_export_over_the_limit_throws(): void
    {
        $service = new TableExportService;

        $this->expectException(ExportTooLargeException::class);

        $service->toPdf('Title', 'Shop', 'All records', ['A', 'B', 'C'], $this->bigRows(TableExportService::MAX_ROWS + 1));
    }

    public function test_an_excel_export_over_the_limit_throws(): void
    {
        $service = new TableExportService;

        $this->expectException(ExportTooLargeException::class);

        $service->toExcel('Title', 'Shop', 'All records', ['A', 'B', 'C'], $this->bigRows(TableExportService::MAX_ROWS + 1), ['string', 'string', 'string']);
    }

    public function test_a_multi_section_pdf_export_sums_rows_across_sections_before_checking_the_limit(): void
    {
        $service = new TableExportService;

        $sections = [
            ['title' => 'Section A', 'headers' => ['A'], 'rows' => $this->bigRows(3000)],
            ['title' => 'Section B', 'headers' => ['A'], 'rows' => $this->bigRows(3000)],
        ];

        $this->expectException(ExportTooLargeException::class);

        $service->toPdfSections('Title', 'Shop', 'All records', $sections);
    }

    public function test_a_multi_section_excel_export_sums_rows_across_sections_before_checking_the_limit(): void
    {
        $service = new TableExportService;

        $sections = [
            ['title' => 'Section A', 'headers' => ['A'], 'rows' => $this->bigRows(3000), 'columnTypes' => ['string']],
            ['title' => 'Section B', 'headers' => ['A'], 'rows' => $this->bigRows(3000), 'columnTypes' => ['string']],
        ];

        $this->expectException(ExportTooLargeException::class);

        $service->toExcelSections('Title', 'Shop', 'All records', $sections);
    }
}
