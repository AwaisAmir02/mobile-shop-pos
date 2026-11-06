<?php

namespace Tests\Feature;

use App\Enums\LimitKey;
use App\Models\MainCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected const HEADERS = ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Selling Price', 'Variant Group', 'Option 1', 'Option 2'];

    protected ProductImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductImportService::class);
        Storage::fake('local');
    }

    protected function shop(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Shop A', 'variants_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Clothing', 'slug' => 'clothing']);

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

    public function test_rows_with_the_same_group_become_one_group_of_variants(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([
            ['', 'Clothing', '', '', '1500', 'Cotton Shirt', 'S', 'Red'],
            ['', 'Clothing', '', '', '1600', 'cotton shirt', 'M', ''],
            ['Belt', 'Clothing', '', '', '300', '', '', ''],
        ]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertTrue($preview['valid'], implode(' ', $preview['rowErrors']));
        $this->assertSame(3, $preview['toCreateCount']);
        $this->assertSame(['Cotton Shirt'], $preview['newGroupNames']);

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertSame(3, $outcome['createdProducts']);
        $this->assertSame(1, $outcome['createdGroups']);

        $group = ProductGroup::sole();
        $this->assertSame(['S / Red', 'M'], $group->variants()->pluck('variant_label')->all());
        $this->assertSame('Cotton Shirt - S / Red', $group->variants()->first()->name);
        $this->assertSame('1600.00', $group->variants()->get()[1]->price);
        $this->assertNull(Product::where('name', 'Belt')->sole()->product_group_id);
    }

    public function test_the_duplicate_check_uses_the_full_variant_name(): void
    {
        $shop = $this->shop();
        $service = app(ProductGroupService::class);
        $group = $service->createGroup($shop->id, 'Cotton Shirt', 'clothing', '');
        $service->addVariants($group, ['S / Red'], 1000, null);

        $path = $this->sheet([
            ['', 'Clothing', '', '', '1500', 'Cotton Shirt', 'S', 'Red'],
            ['', 'Clothing', '', '', '1500', 'Cotton Shirt', 'M', 'Red'],
        ]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertSame(1, $preview['toSkipCount']);
        $this->assertSame(1, $preview['toCreateCount']);
        $this->assertSame([], $preview['newGroupNames']);

        $this->service->commit($path, $shop->id);

        $this->assertSame(2, $group->variants()->count());
        $this->assertSame(1, ProductGroup::count());
    }

    public function test_the_same_variant_twice_in_a_sheet_is_an_error(): void
    {
        $shop = $this->shop();
        $path = $this->sheet([
            ['', 'Clothing', '', '', '1500', 'Shirt', 'S', ''],
            ['', 'Clothing', '', '', '1500', 'Shirt', 's', ''],
        ]);

        $preview = $this->service->preview($path, $shop->id);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Shirt - s', implode(' ', $preview['fileErrors']));
    }

    public function test_invalid_variant_rows_are_refused(): void
    {
        $shop = $this->shop();
        $service = app(ProductGroupService::class);
        $service->addVariants($service->createGroup($shop->id, 'Existing', 'clothing', ''), ['S'], 100, null);

        $errors = $this->service->preview($this->sheet([
            ['', 'Clothing', '', '', '100', 'No Options', '', ''],
            ['', 'Mobile Phone', '', 'Samsung', '100', 'Phones', '64GB', ''],
            ['', 'Accessory', 'Cable', '', '100', 'Existing', 'M', ''],
            ['', 'Clothing', '', '', '100', 'Mixed', 'S', ''],
            ['', 'Accessory', 'Cable', '', '100', 'Mixed', 'M', ''],
        ]), $shop->id)['rowErrors'];

        $text = implode(' | ', $errors);
        $this->assertStringContainsString('Row 2: Option 1 or Option 2 is required', $text);
        $this->assertStringContainsString("Row 3: IMEI-tracked mobiles and SIMs can't have variants", $text);
        $this->assertStringContainsString('Row 4: Variant Group "Existing" already exists under a different category', $text);
        $this->assertStringContainsString('Row 6: Every row of Variant Group "Mixed"', $text);
    }

    public function test_with_the_feature_off_the_columns_are_ignored_with_a_notice(): void
    {
        $shop = $this->shop(['variants_enabled' => false]);
        $path = $this->sheet([['Belt', 'Clothing', '', '', '300', 'Ignored', 'S', '']]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertTrue($preview['valid']);
        $this->assertStringContainsString('product variants are turned off', implode(' ', $preview['notices']));

        $this->service->commit($path, $shop->id);

        $this->assertSame(0, ProductGroup::count());
        $this->assertSame('Belt', Product::sole()->name);
        $this->assertNull(Product::sole()->product_group_id);
    }

    public function test_a_group_uses_one_place_on_the_plan_however_many_rows_it_has(): void
    {
        $plan = Plan::create(['name' => 'Tiny', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['products' => 2], 'feature_flags' => []]);
        $shop = $this->shop(['plan_id' => $plan->id]);

        $path = $this->sheet([
            ['', 'Clothing', '', '', '100', 'Shirt', 'S', ''],
            ['', 'Clothing', '', '', '100', 'Shirt', 'M', ''],
            ['', 'Clothing', '', '', '100', 'Shirt', 'L', ''],
            ['Belt', 'Clothing', '', '', '100', '', '', ''],
            ['Cap', 'Clothing', '', '', '100', '', '', ''],
            ['', 'Clothing', '', '', '100', 'Shirt', 'XL', ''],
        ]);

        $preview = $this->service->preview($path, $shop->id);
        $this->assertStringContainsString('room for 2 more', implode(' ', $preview['notices']));
        $this->assertStringContainsString('the other 1 will be skipped', implode(' ', $preview['notices']));

        $outcome = $this->service->commit($path, $shop->id);

        $this->assertSame(5, $outcome['createdProducts']);
        $this->assertSame(1, $outcome['limitSkippedProducts']);
        $this->assertStringContainsString('Cap', $outcome['limitSkippedLines'][0]);
        $this->assertSame(4, ProductGroup::sole()->variants()->count());
        $this->assertSame(2, app(Entitlements::class)->usage($shop, LimitKey::Products));
    }

    public function test_the_template_has_the_variant_columns_only_when_the_feature_is_on(): void
    {
        $headers = function (bool $withVariants): array {
            ob_start();
            $this->service->templateResponse(true, $withVariants)->sendContent();
            $path = Storage::disk('local')->path('template-'.uniqid().'.xlsx');
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, ob_get_clean());

            return IOFactory::load($path)->getSheetByName('Products')->rangeToArray('A1:M1')[0];
        };

        $this->assertNotContains('Variant Group', $headers(false));
        $this->assertContains('Variant Group', $headers(true));
    }
}
