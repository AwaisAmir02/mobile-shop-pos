<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ProductImportBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected const HEADERS = ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty', 'Barcode'];

    protected function sheet(array $rows, array $headers = self::HEADERS): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = sys_get_temp_dir().'/'.uniqid('barcode_import_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    protected function shop(bool $barcodesEnabled = true): Shop
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true, 'barcode_enabled' => $barcodesEnabled]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
        \App\Models\MainCategory::ensureDefaultsExist($shop->id);

        return $shop;
    }

    public function test_a_valid_barcode_column_is_previewed_and_saved_on_commit(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10', '2000000000015']]);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);
        $this->assertTrue($preview['valid']);
        $this->assertSame(1, $preview['toCreateCount']);

        app(ProductImportService::class)->commit($path, $shop->id);

        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'USB Cable', 'barcode' => '2000000000015']);
    }

    public function test_a_blank_barcode_cell_is_stored_as_null(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10', '']]);

        app(ProductImportService::class)->commit($path, $shop->id);

        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'USB Cable', 'barcode' => null]);
    }

    public function test_a_barcode_repeated_inside_the_same_sheet_is_a_row_error_naming_both_rows(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([
            ['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10', '2000000000015'],
            ['Charger', 'Accessory', 'Charger', '', '', '', '900', '600', '4', '2000000000015'],
        ]);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertContains('Row 3: Barcode "2000000000015" is also on row 2.', $preview['rowErrors']);
    }

    public function test_a_barcode_belonging_to_an_existing_product_is_a_row_error_naming_that_product(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Galaxy A15', 'price' => 1, 'barcode' => '2000000000015', 'details' => []]);
        $path = $this->sheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10', '2000000000015']]);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertContains('Row 2: Barcode "2000000000015" is already used by "Galaxy A15".', $preview['rowErrors']);
    }

    public function test_the_existing_name_and_imei_duplicate_rule_is_unchanged_a_skipped_row_is_not_a_barcode_error(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15', 'price' => 1, 'barcode' => '2000000000015', 'details' => ['imei' => '351756051523999', 'brand' => 'Samsung']]);
        $path = $this->sheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '45000', '39000', '5', '2000000000015']]);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame(0, $preview['toCreateCount']);
        $this->assertSame(1, $preview['toSkipCount']);
    }

    public function test_the_barcode_column_is_ignored_with_a_notice_when_barcodes_are_off(): void
    {
        $shop = $this->shop(barcodesEnabled: false);
        $path = $this->sheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10', '2000000000015']]);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertNotEmpty($preview['notices']);
        $this->assertStringContainsString('Barcode column was ignored', $preview['notices'][0]);

        app(ProductImportService::class)->commit($path, $shop->id);
        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'USB Cable', 'barcode' => null]);
    }

    public function test_an_import_without_a_barcode_column_still_works_exactly_as_before(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10']], array_slice(self::HEADERS, 0, 9));

        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame([], $preview['notices']);
    }

    protected function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_the_template_includes_a_barcode_column_when_barcodes_are_on(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('template_on_', true).'.xlsx';
        file_put_contents($path, $this->capture(app(ProductImportService::class)->templateResponse(true)));

        $products = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Products');
        $this->assertSame('Barcode', $products->getCell('J1')->getValue());
        $this->assertSame('@', $products->getStyle('J:J')->getNumberFormat()->getFormatCode());
    }

    public function test_the_template_omits_the_barcode_column_when_barcodes_are_off(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('template_off_', true).'.xlsx';
        file_put_contents($path, $this->capture(app(ProductImportService::class)->templateResponse(false)));

        $products = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Products');
        $this->assertNotContains('Barcode', $products->rangeToArray('A1:J1')[0]);
    }

    public function test_the_example_sheet_carries_a_worked_barcode(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('template_example_', true).'.xlsx';
        file_put_contents($path, $this->capture(app(ProductImportService::class)->templateResponse(true)));

        $example = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Example');
        $this->assertSame('2000000000015', (string) $example->getCell('J2')->getValue());
    }
}
