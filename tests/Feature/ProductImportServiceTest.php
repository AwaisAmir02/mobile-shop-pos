<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Exercises App\Services\ProductImportService directly against real xlsx
 * files (built with PhpSpreadsheet, the same library the importer itself
 * reads with) — this is the single place every rule from the approved plan
 * (required/optional columns, find-or-create matching, duplicate handling,
 * all-or-nothing validation, Excel quirks) actually lives, shared by both
 * the preview and confirm steps.
 */
class ProductImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProductImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductImportService;
        Storage::fake('local');
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  Each inner array is a full data row, in header order.
     * @param  array<int, string>|null  $headers  Defaults to the full canonical header set.
     */
    protected function buildSheet(array $rows, ?array $headers = null, ?string $imeiColumnLetter = 'F'): string
    {
        $headers ??= ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');
        $sheet->fromArray($headers, null, 'A1');

        if ($imeiColumnLetter) {
            $sheet->getStyle($imeiColumnLetter.':'.$imeiColumnLetter)->getNumberFormat()->setFormatCode('@');
        }

        $sheet->fromArray($rows, null, 'A2');

        $path = Storage::disk('local')->path('test-imports/'.uniqid('sheet_', true).'.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    protected function shop(): Shop
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        MainCategory::ensureDefaultsExist($shop->id);

        return $shop;
    }

    // ── Column requirements ────────────────────────────────────────────

    public function test_missing_a_required_header_is_a_file_error(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']], headers: ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Cost Price', 'Stock Qty']);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Selling Price', implode(' ', $preview['fileErrors']));
    }

    public function test_name_main_category_and_price_are_required(): void
    {
        $shop = $this->shop();
        // Not a fully blank row (Cost Price is filled) — a completely blank
        // row is deliberately ignored rather than validated (see the
        // "completely blank rows are skipped" test below), so this row
        // needs at least one non-blank cell to actually be checked.
        $path = $this->buildSheet([['', '', '', '', '', '', '', '100', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Name is required', implode(' ', $preview['rowErrors']));
        $this->assertStringContainsString('Main Category is required', implode(' ', $preview['rowErrors']));
        $this->assertStringContainsString('Selling Price is required', implode(' ', $preview['rowErrors']));
    }

    public function test_sub_category_is_required_only_for_accessory(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', '', '', '', '', '500', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Sub-Category is required because Main Category is Accessory', implode(' ', $preview['rowErrors']));
    }

    public function test_brand_is_required_only_for_mobile_phone(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', '', '', '', '25000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Brand is required because Main Category is Mobile Phone', implode(' ', $preview['rowErrors']));
    }

    public function test_a_custom_main_category_requires_neither_sub_category_nor_brand(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Dell XPS', 'Laptops', '', '', '', '', '150000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame(1, $preview['toCreateCount']);
    }

    public function test_cost_price_and_stock_qty_are_optional(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
    }

    public function test_model_and_imei_are_ignored_for_non_mobile_rows(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', 'Cable', '', 'SomeModel', '12345', '500', '', '']]);

        $shop2 = $shop; // keep variable used for clarity below
        $outcome = $this->service->commit($path, $shop2->id);

        $this->assertTrue($outcome['success']);
        $product = Product::first();
        $this->assertArrayNotHasKey('model', $product->details);
        $this->assertArrayNotHasKey('imei', $product->details);
    }

    // ── Find-or-create matching ─────────────────────────────────────────

    public function test_an_existing_main_category_is_matched_trimmed_and_case_insensitively_not_duplicated(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', ' accessory ', 'Cable', '', '', '', '500', '', '']]);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertTrue($outcome['success']);
        $this->assertSame(0, $outcome['createdMainCategories']);
        $this->assertSame(2, MainCategory::count()); // still just the two builtins
    }

    public function test_a_brand_new_main_category_sub_category_and_brand_are_created(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', 'Sumsang Electronics', 'A15', '', '25000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertTrue($preview['valid']);
        $this->assertSame(['Sumsang Electronics'], $preview['newBrandNames']);

        $outcome = $this->service->commit($path, $shop->id);
        $this->assertTrue($outcome['success']);
        $this->assertSame(1, $outcome['createdBrands']);
        $this->assertTrue(Brand::where('name', 'Sumsang Electronics')->exists());
    }

    /**
     * accessory_category_options has a UNIQUE(shop_id, name) constraint —
     * confirmed against the actual migration — so a sub-category name is
     * unique for the whole shop, not per Main Category. A row naming a
     * Sub-Category that already exists under a DIFFERENT Main Category
     * must match that existing row (the database has no way to hold a
     * second one with the same name), not attempt — and fail — to create a duplicate.
     */
    public function test_a_sub_category_name_that_already_exists_under_a_different_main_category_is_matched_not_duplicated(): void
    {
        $shop = $this->shop();
        $accessory = MainCategory::where('slug', 'accessory')->first();
        AccessoryCategoryOption::create(['main_category_id' => $accessory->id, 'name' => 'Cable']);

        $path = $this->buildSheet([['Laptop Bag', 'Laptops', 'Cable', '', '', '', '2000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertTrue($preview['valid']);
        $this->assertEmpty($preview['newSubCategoryNames']);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertTrue($outcome['success']);
        $this->assertSame(0, $outcome['createdSubCategories']);
        $this->assertSame(1, AccessoryCategoryOption::where('name', 'Cable')->count());
        $this->assertSame('Cable', Product::firstOrFail()->details['category']);
    }

    public function test_two_rows_referencing_the_same_new_category_only_create_it_once(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([
            ['Dell XPS', 'Laptops', '', '', '', '', '150000', '', ''],
            ['HP Pavilion', 'laptops', '', '', '', '', '90000', '', ''],
        ]);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertTrue($outcome['success']);
        $this->assertSame(1, $outcome['createdMainCategories']);
        $this->assertSame(2, $outcome['createdProducts']);
    }

    public function test_a_custom_main_category_row_is_created_exactly_like_the_add_product_form_would(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Dell XPS', 'Laptops', '', '', '', '', '150000', '120000', '3']]);

        $this->service->commit($path, $shop->id);

        $category = MainCategory::where('slug', 'laptops')->firstOrFail();
        $this->assertSame('Laptops', $category->name);
        $this->assertFalse($category->is_builtin);

        $product = Product::firstOrFail();
        $this->assertSame('laptops', $product->type);
        $this->assertSame(['category' => null], $product->details);
    }

    // ── Duplicate handling ──────────────────────────────────────────────

    public function test_a_row_matching_an_existing_products_name_and_imei_is_skipped_not_overwritten(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15', 'price' => 20000, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => '351756051523999']]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '25000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertSame(0, $preview['toCreateCount']);
        $this->assertSame(1, $preview['toSkipCount']);

        $outcome = $this->service->commit($path, $shop->id);
        $this->assertSame(1, $outcome['skippedProducts']);
        $this->assertSame(1, Product::count()); // still just the original — price 20000 untouched
        $this->assertEquals(20000, Product::first()->price);
    }

    public function test_the_same_name_with_a_different_imei_is_not_a_duplicate(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15', 'price' => 20000, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => '111111111111111']]);
        Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '222222222222222', '25000', '', '']]);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertSame(0, $outcome['skippedProducts']);
        $this->assertSame(1, $outcome['createdProducts']);
        $this->assertSame(2, Product::count());
    }

    public function test_blank_imei_matches_blank_imei_for_duplicate_detection(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);
        AccessoryCategoryOption::create(['main_category_id' => MainCategory::where('slug', 'accessory')->value('id'), 'name' => 'Cable']);

        $path = $this->buildSheet([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertSame(1, $outcome['skippedProducts']);
        $this->assertSame(0, $outcome['createdProducts']);
    }

    public function test_two_rows_in_the_same_sheet_with_the_same_name_and_imei_are_a_file_error_naming_both_rows(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([
            ['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '25000', '', ''],
            ['Some Other Row', 'Accessory', 'Cable', '', '', '', '500', '', ''],
            ['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '26000', '', ''],
        ]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $error = implode(' ', $preview['fileErrors']);
        $this->assertStringContainsString('Rows 2 and 4', $error);
        $this->assertSame(0, Product::count());
    }

    // ── All-or-nothing ───────────────────────────────────────────────────

    public function test_one_invalid_row_means_zero_writes_including_zero_new_categories(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([
            ['Dell XPS', 'Laptops', '', '', '', '', '150000', '', ''], // valid, introduces a new category
            ['', 'Laptops', '', '', '', '', '90000', '', ''],          // invalid — blank name
        ]);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertFalse($outcome['success']);
        $this->assertSame(0, Product::count());
        $this->assertSame(2, MainCategory::count()); // only the two builtins — "Laptops" never created
    }

    public function test_preview_never_writes_anything(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Dell XPS', 'Laptops', '', '', '', '', '150000', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame(0, Product::count());
        $this->assertSame(2, MainCategory::count());
    }

    public function test_commit_is_transactional_and_re_validates_rather_than_trusting_a_stale_preview(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Dell XPS', 'Laptops', '', '', '', '', '150000', '', '']]);

        $this->service->preview($path, $shop->id);

        // The file on disk changes to something invalid before Confirm is clicked.
        file_put_contents($path, ''); // corrupt the file entirely

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertFalse($outcome['success']);
        $this->assertSame(0, Product::count());
    }

    // ── Excel quirks ─────────────────────────────────────────────────────

    public function test_a_numeric_imei_cell_is_coerced_to_a_full_digit_string_without_scientific_notation(): void
    {
        $shop = $this->shop();
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        // Written as a genuine float cell (no Text format), matching what happens
        // when a shopkeeper types a long number into a General-formatted cell.
        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', 351756051523999, '25000', '', '']], imeiColumnLetter: null);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertTrue($outcome['success']);
        $imei = Product::first()->details['imei'];
        $this->assertStringNotContainsString('E+', $imei);
        $this->assertStringNotContainsString('.', $imei);
    }

    public function test_price_with_currency_text_and_commas_is_cleaned(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', 'Cable', '', '', '', 'Rs 1,500.00', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);

        $outcome = $this->service->commit($path, $shop->id);
        $this->assertEquals(1500.00, Product::first()->price);
    }

    public function test_a_price_that_does_not_parse_after_cleaning_is_a_row_error(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Cable', 'Accessory', 'Cable', '', '', '', 'not a price', '', '']]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Selling Price is not a valid number', implode(' ', $preview['rowErrors']));
    }

    // ── Limits ───────────────────────────────────────────────────────────

    public function test_more_than_the_row_cap_is_a_file_error_before_any_heavy_work(): void
    {
        $shop = $this->shop();
        $rows = [];
        for ($i = 0; $i < ProductImportService::MAX_ROWS + 1; $i++) {
            $rows[] = ["Product {$i}", 'Accessory', 'Cable', '', '', '', '500', '', ''];
        }
        $path = $this->buildSheet($rows);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString((string) ProductImportService::MAX_ROWS, implode(' ', $preview['fileErrors']));
    }

    public function test_completely_blank_rows_are_skipped_not_counted_or_errored(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([
            ['Cable', 'Accessory', 'Cable', '', '', '', '500', '', ''],
            ['', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', ''],
        ]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame(1, $preview['toCreateCount']);
    }

    // ── Similarity warnings ──────────────────────────────────────────────

    public function test_a_new_main_category_name_very_close_to_an_existing_one_is_flagged(): void
    {
        $shop = $this->shop();
        $path = $this->buildSheet([['Some Phone', 'Mobile Phne', '', 'Samsung', '', '', '25000', '', '']]);
        Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertNotEmpty($preview['similarWarnings']);
        $this->assertStringContainsString('Mobile Phne', implode(' ', $preview['similarWarnings']));
    }

    // ── Tenant isolation ─────────────────────────────────────────────────

    public function test_matching_and_duplicate_detection_never_cross_shop_boundaries(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        MainCategory::ensureDefaultsExist($shopA->id);
        MainCategory::ensureDefaultsExist($shopB->id);

        // Shop B already has this exact product/brand — must not affect Shop A's import.
        Product::create(['shop_id' => $shopB->id, 'type' => 'mobile', 'name' => 'Galaxy A15', 'price' => 20000, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => '351756051523999']]);
        Brand::create(['shop_id' => $shopB->id, 'name' => 'Samsung']);

        $this->actingAs($ownerA);
        $path = $this->buildSheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '25000', '', '']]);

        $outcome = $this->service->commit($path, $shopA->id);

        $this->assertTrue($outcome['success']);
        $this->assertSame(1, $outcome['createdProducts']); // not skipped — Shop B's product is irrelevant
        $this->assertSame(1, $outcome['createdBrands']);   // Shop B's Brand is irrelevant too
        $this->assertSame($shopA->id, Product::where('name', 'Galaxy A15')->where('shop_id', $shopA->id)->firstOrFail()->shop_id);
        $this->assertSame($shopA->id, Brand::where('name', 'Samsung')->where('shop_id', $shopA->id)->firstOrFail()->shop_id);
    }
}
