<?php

namespace Tests\Feature;

use App\Enums\QuantityUnit;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportMeasuredTest extends TestCase
{
    use RefreshDatabase;

    protected const HEADERS = ['Name', 'Main Category', 'Selling Price', 'Stock Qty', 'Unit', 'Variant Group', 'Option 1'];

    protected ProductImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductImportService::class);
        Storage::fake('local');
    }

    protected function shop(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Shop A', 'measured_units_enabled' => true, 'variants_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Grocery', 'slug' => 'grocery']);

        return $shop;
    }

    protected function sheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = Storage::disk('local')->path('test-imports/'.uniqid('sheet_', true).'.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_a_unit_column_gives_decimals_and_blank_means_piece(): void
    {
        $shop = $this->shop();

        $result = $this->service->commit($this->sheet([
            ['Rice', 'Grocery', '320', '25.5', 'KG', '', ''],
            ['Oil', 'Grocery', '500', 3.25, 'liter', '', ''],
            ['Spoon', 'Grocery', '50', '10', '', '', ''],
        ]), $shop->id);

        $this->assertTrue($result['success']);
        $this->assertSame(25.5, Product::where('name', 'Rice')->first()->stock_quantity);
        $this->assertSame(QuantityUnit::Kg, Product::where('name', 'Rice')->first()->unit);
        $this->assertSame(3.25, Product::where('name', 'Oil')->first()->stock_quantity);
        $this->assertSame(QuantityUnit::Litre, Product::where('name', 'Oil')->first()->unit);
        $this->assertSame(QuantityUnit::Piece, Product::where('name', 'Spoon')->first()->unit);
    }

    public function test_bad_units_and_quantities_are_row_errors(): void
    {
        $shop = $this->shop();

        $preview = $this->service->preview($this->sheet([
            ['Spoon', 'Grocery', '50', '1.5', '', '', ''],
            ['Rice', 'Grocery', '320', '1.2345', 'kg', '', ''],
            ['Rope', 'Grocery', '10', '2', 'dozen', '', ''],
            ['Phone', 'Mobile Phone', '10', '2', 'kg', '', ''],
        ]), $shop->id);

        $errors = implode(' ', $preview['rowErrors']);
        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Row 2: Stock Qty is not a valid whole number', $errors);
        $this->assertStringContainsString('Row 3: Stock Qty must be a number with at most 3 decimal places', $errors);
        $this->assertStringContainsString('Row 4: Unit must be piece, kg', $errors);
        $this->assertStringContainsString('Row 5: Mobiles and SIMs are sold by the piece', $errors);
    }

    public function test_a_group_shares_one_unit_and_blank_rows_follow_it(): void
    {
        $shop = $this->shop();

        $result = $this->service->commit($this->sheet([
            ['', 'Grocery', '400', '2.5', 'metre', 'Lawn Cloth', 'Red'],
            ['', 'Grocery', '400', '1.5', '', 'Lawn Cloth', 'Blue'],
        ]), $shop->id);

        $this->assertTrue($result['success']);
        $this->assertSame(QuantityUnit::Metre, ProductGroup::first()->unit);
        $this->assertSame(1.5, Product::where('variant_label', 'Blue')->first()->stock_quantity);
        $this->assertSame(QuantityUnit::Metre, Product::where('variant_label', 'Blue')->first()->unit);

        $preview = $this->service->preview($this->sheet([
            ['', 'Grocery', '400', '1', 'yard', 'Lawn Cloth', 'Green'],
            ['', 'Grocery', '400', '1', 'kg', 'Silk', 'Red'],
            ['', 'Grocery', '400', '1', 'gaz', 'Silk', 'Blue'],
        ]), $shop->id);

        $errors = implode(' ', $preview['rowErrors']);
        $this->assertStringContainsString('"Lawn Cloth" is sold by metre', $errors);
        $this->assertStringContainsString('Every row of Variant Group "Silk" must have the same Unit', $errors);
    }

    public function test_with_the_feature_off_the_unit_column_is_ignored(): void
    {
        $shop = $this->shop(['measured_units_enabled' => false]);
        $path = $this->sheet([['Rice', 'Grocery', '320', '25', 'kg', '', '']]);

        $this->assertStringContainsString('Unit column was ignored', implode(' ', $this->service->preview($path, $shop->id)['notices']));

        $this->service->commit($path, $shop->id);
        $this->assertSame(QuantityUnit::Piece, Product::where('name', 'Rice')->first()->unit);
    }

    public function test_the_template_offers_the_unit_column_only_when_on(): void
    {
        $headers = function (bool $withUnits) {
            ob_start();
            $this->service->templateResponse(true, false, $withUnits)->sendContent();
            $path = Storage::disk('local')->path('template-'.uniqid().'.xlsx');
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, ob_get_clean());

            return IOFactory::load($path)->getSheet(0)->toArray()[0];
        };

        $this->assertContains('Unit', $headers(true));
        $this->assertNotContains('Unit', $headers(false));
    }
}
