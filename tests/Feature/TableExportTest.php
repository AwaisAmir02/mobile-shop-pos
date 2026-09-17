<?php

namespace Tests\Feature;

use App\Exports\TableExport;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Covers the shared export mechanism (TableExportService/TableExport) via
 * Sales History, since all four screens route through the exact same
 * classes — these prove the mechanism itself is correct. Per-screen tests
 * only need to confirm each screen wires its own filters/columns in
 * correctly, not re-prove the export machinery.
 */
class TableExportTest extends TestCase
{
    use RefreshDatabase;

    /** Invokes a protected/private method on a Livewire component instance directly. */
    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }

    public function test_sales_history_export_with_a_date_filter_only_includes_matching_rows(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        // created_at isn't mass-assignable, so backdate it via a direct
        // property set (bypasses the fillable guard) rather than create().
        $inRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        $inRange->created_at = '2026-09-10 10:00:00';
        $inRange->save();

        $outOfRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);
        $outOfRange->created_at = '2026-08-01 10:00:00';
        $outOfRange->save();

        $this->actingAs($owner);

        $component = Livewire::test('sales.history')
            ->set('from', '2026-09-01')
            ->set('to', '2026-09-30');

        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);

        $this->assertCount(1, $rows);
        $this->assertSame('Rs 100.00', $rows[0][4]);

        $summary = $this->callProtected($component->instance(), 'exportFiltersSummary');
        $this->assertStringContainsString('01 Sep 2026', $summary);
        $this->assertStringContainsString('30 Sep 2026', $summary);
    }

    public function test_sales_history_export_with_no_filter_includes_everything(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);

        $this->actingAs($owner);

        $component = Livewire::test('sales.history');

        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);
        $this->assertCount(2, $rows);

        $summary = $this->callProtected($component->instance(), 'exportFiltersSummary');
        $this->assertSame('All records', $summary);
    }

    public function test_exporting_to_pdf_and_excel_produces_a_downloadable_response(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);

        $this->actingAs($owner);

        Excel::fake();

        $pdfResponse = Livewire::test('sales.history')->call('exportPdf');
        $pdfResponse->assertStatus(200);

        Livewire::test('sales.history')->call('exportExcel');

        Excel::matchByRegex();
        Excel::assertDownloaded(
            '/^sales-history-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/',
            fn (TableExport $export) => count($export->array()) === 7 // 4 meta rows + spacer + header + 1 data row
        );
    }

    public function test_the_excel_files_numeric_and_date_columns_are_genuinely_typed_not_text(): void
    {
        $export = new TableExport(
            'Sales History',
            'Shop A',
            'All records',
            ['Invoice', 'Date', 'Customer', 'Items', 'Total', 'Paid', 'Status'],
            [['INV-000001', TableExport::excelDate(Carbon::parse('2026-09-10 10:00:00')), 'Walk-in', 1, 1500.0, 1500.0, 'Paid']],
            ['string', 'date', 'string', 'integer', 'currency', 'currency', 'string'],
        );

        Storage::fake('local');
        Excel::store($export, 'test-export.xlsx', 'local', ExcelWriter::XLSX);

        $spreadsheet = IOFactory::load(Storage::disk('local')->path('test-export.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();

        // Row 6 is the header row (5 meta rows above it); row 7 is the first data row.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B7')->getDataType(), 'Date column must be a real numeric date cell.');
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D7')->getDataType(), 'Integer column must be numeric.');
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E7')->getDataType(), 'Currency column must be numeric.');
        $this->assertEquals(1500.0, $sheet->getCell('E7')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('E7')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd mmm yyyy', $sheet->getStyle('B7')->getNumberFormat()->getFormatCode());

        $this->assertSame('Invoice', $sheet->getCell('A6')->getValue());
        $this->assertTrue($sheet->getStyle('A6')->getFont()->getBold());
    }

    public function test_a_shop_can_only_export_its_own_sales(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        Sale::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        Sale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'subtotal' => 999, 'discount_amount' => 0, 'total' => 999]);

        $this->actingAs($ownerA);

        $component = Livewire::test('sales.history');
        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);

        $this->assertCount(1, $rows);
        $this->assertSame('Rs 100.00', $rows[0][4]);
    }

    public function test_a_role_without_sales_access_cannot_reach_sales_history_to_export(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => []]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('sales.history'))->assertForbidden();
    }

    /**
     * A large dataset (enough rows to force multi-page PDF pagination)
     * must still render without error and carry every row through to the
     * Excel file — this doesn't inspect the rendered PDF pixels (that
     * needs a human), but does prove the pipeline holds up at volume
     * rather than only in a two-row happy path.
     */
    public function test_a_large_dataset_exports_successfully_to_both_formats_without_dropping_rows(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        for ($i = 0; $i < 150; $i++) {
            Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100 + $i, 'discount_amount' => 0, 'total' => 100 + $i]);
        }

        $this->actingAs($owner);

        $component = Livewire::test('sales.history');
        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);
        $this->assertCount(150, $rows);

        $pdfResponse = $component->call('exportPdf');
        $pdfResponse->assertStatus(200);

        Excel::fake();
        Livewire::test('sales.history')->call('exportExcel');
        Excel::matchByRegex();
        Excel::assertDownloaded(
            '/^sales-history-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/',
            fn (TableExport $export) => count($export->array()) === 156 // 6 meta/header rows + 150 data rows
        );
    }
}
