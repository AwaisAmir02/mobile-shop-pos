<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use App\Support\Imei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImeiValidationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = '490154203237518';

    private const VALID_2 = '351756051523993';

    private const VALID_3 = '356789104523111';

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Ali Mobiles']);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id]));
        MainCategory::ensureDefaultsExist($this->shop->id);
        Brand::create(['shop_id' => $this->shop->id, 'name' => 'Samsung']);
    }

    protected function phone(string $name, ?string $imei, array $extra = []): Product
    {
        return Product::create($extra + ['shop_id' => $this->shop->id, 'type' => 'mobile', 'name' => $name, 'price' => 30000, 'stock_quantity' => 1,
            'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => $imei, 'category' => null]]);
    }

    protected function productForm(string $imei, ?int $editId = null)
    {
        $component = Livewire::test('products.index');

        if ($editId) {
            $component->call('openEdit', $editId);
        } else {
            $component->set('type', 'mobile')->set('name', 'Galaxy A15')->set('brand', 'Samsung')->set('price', '30000')->set('stock_quantity', '1');
        }

        return $component->set('imei', $imei)->call('save');
    }

    public function test_an_imei_is_15_digits_with_a_matching_check_digit(): void
    {
        $this->assertTrue(Imei::isValid(self::VALID));
        $this->assertFalse(Imei::isValid('490154203237519'));
        $this->assertFalse(Imei::isValid('49015420323751'));
        $this->assertSame(self::VALID, Imei::normalize(' 49-015420-323751-8 '));
        $this->assertStringContainsString('exactly 15 digits; this has 3', Imei::problem('123'));
        $this->assertStringContainsString('last digit doesn\'t match', Imei::problem('490154203237519'));
        $this->assertStringContainsString('digits only', Imei::problem('ABC123'));
    }

    public function test_the_product_form_refuses_a_malformed_imei_and_stores_a_valid_one_as_digits(): void
    {
        $this->productForm('123')->assertHasErrors(['imei']);
        $this->productForm('490154203237519')->assertHasErrors(['imei']);
        $this->assertSame(0, Product::count());

        $this->productForm('49 015420 323751 8')->assertHasNoErrors();
        $this->assertSame(self::VALID, Product::sole()->details['imei']);
    }

    public function test_an_imei_already_on_another_product_is_refused_naming_that_product(): void
    {
        $this->phone('Galaxy A05', self::VALID, ['stock_quantity' => 0]);

        $this->productForm(self::VALID)->assertHasErrors(['imei'])->assertSee('This IMEI is already on &quot;Galaxy A05&quot; (sold or out of stock).', false);

        Product::where('name', 'Galaxy A05')->update(['archived_at' => now()]);
        $this->productForm(self::VALID)->assertSee('(archived)');

        $this->assertSame(1, Product::count());
    }

    public function test_editing_a_product_keeps_its_own_imei_and_older_entries_can_still_be_saved(): void
    {
        $own = $this->phone('Galaxy A15', self::VALID);
        $this->productForm(self::VALID, $own->id)->assertHasNoErrors();

        // Saved before the check existed: still editable as long as the IMEI isn't changed.
        $legacy = $this->phone('Old Nokia', '12345');
        $this->productForm('12345', $legacy->id)->assertHasNoErrors();
        $this->productForm('54321', $legacy->id)->assertHasErrors(['imei']);
        $this->productForm(self::VALID, $legacy->id)->assertHasErrors(['imei']);
        $this->productForm(self::VALID_2, $legacy->id)->assertHasNoErrors();
        $this->assertSame(self::VALID_2, $legacy->fresh()->details['imei']);
    }

    public function test_quick_create_applies_the_same_checks(): void
    {
        $this->phone('Galaxy A05', self::VALID);
        $quick = fn (string $imei) => Livewire::test('products.quick-create')->set('type', 'mobile')->set('name', 'Galaxy A15')->set('brand', 'Samsung')->set('price', '30000')->set('imei', $imei)->call('save');

        $quick('123456789012345')->assertHasErrors(['imei']);
        $quick(self::VALID)->assertHasErrors(['imei']);
        $quick(self::VALID_2)->assertHasNoErrors();

        $this->assertSame(self::VALID_2, Product::where('name', 'Galaxy A15')->sole()->details['imei']);
    }

    protected function sheet(array $rows): string
    {
        Storage::fake('local');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');
        $sheet->fromArray(['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'], null, 'A1');
        $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode('@');
        $sheet->fromArray($rows, null, 'A2');
        $path = Storage::disk('local')->path('imei-'.uniqid().'.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_the_import_names_each_bad_or_reused_imei_by_row_and_imports_nothing(): void
    {
        $this->phone('Galaxy A05', self::VALID);

        $preview = (new ProductImportService)->preview($this->sheet([
            ['Phone One', 'Mobile Phone', '', 'Samsung', '', '12345', '30000', '', '1'],
            ['Phone Two', 'Mobile Phone', '', 'Samsung', '', '490154203237519', '30000', '', '1'],
            ['Phone Three', 'Mobile Phone', '', 'Samsung', '', self::VALID, '30000', '', '1'],
            ['Phone Four', 'Mobile Phone', '', 'Samsung', '', self::VALID_2, '30000', '', '1'],
            ['Phone Five', 'Mobile Phone', '', 'Samsung', '', '35175605-1523993', '30000', '', '1'],
            ['Phone Six', 'Mobile Phone', '', 'Samsung', '', self::VALID_3, '30000', '', '1'],
        ]), $this->shop->id);

        $errors = implode("\n", $preview['rowErrors']);
        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Row 2: IMEI "12345": An IMEI is exactly 15 digits', $errors);
        $this->assertStringContainsString('Row 3: IMEI "490154203237519": This isn\'t a valid IMEI', $errors);
        $this->assertStringContainsString('Row 4: IMEI "'.self::VALID.'" is already on "Galaxy A05".', $errors);
        $this->assertStringContainsString('Row 6: IMEI "'.self::VALID_2.'" is also on row 5.', $errors);
        $this->assertStringNotContainsString('Row 7', $errors);
        $this->assertSame(1, Product::count());
    }

    public function test_a_clean_import_stores_digits_and_a_re_import_is_still_skipped(): void
    {
        $service = new ProductImportService;
        $path = $this->sheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', '', '35-175605-152399-3', '30000', '', '1']]);

        $this->assertSame(1, $service->commit($path, $this->shop->id)['createdProducts']);
        $this->assertSame(self::VALID_2, Product::sole()->details['imei']);

        $again = $service->commit($this->sheet([['Galaxy A15', 'Mobile Phone', '', 'Samsung', '', self::VALID_2, '30000', '', '1']]), $this->shop->id);
        $this->assertSame(1, $again['skippedProducts']);
        $this->assertSame(1, Product::count());
    }

    public function test_the_warranty_lookup_and_export_still_find_the_stored_imei(): void
    {
        $this->productForm('49 015420 323751 8')->assertHasNoErrors();
        $product = Product::sole();
        \App\Actions\CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, null, auth()->id(), now()->toDateString(), 30000);

        Livewire::test('sales.warranty')->set('search', self::VALID)->assertSee('Galaxy A15');
        Livewire::test('sales.warranty')->set('search', '3237518')->assertSee('Galaxy A15');
    }
}
