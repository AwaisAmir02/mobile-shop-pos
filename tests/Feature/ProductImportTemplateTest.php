<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** Captures a StreamedResponse's real output the same way Livewire's own SupportFileDownloads hook does — see that vendor class for the identical ob_start()/sendContent() technique. */
    protected function capture(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_the_template_file_opens_with_the_expected_headers_on_the_products_sheet(): void
    {
        $binary = $this->capture(app(ProductImportService::class)->templateResponse());

        $path = Storage::disk('local')->path('template-test-'.uniqid().'.xlsx');
        file_put_contents($path, $binary);

        $spreadsheet = IOFactory::load($path);
        $products = $spreadsheet->getSheetByName('Products');

        $this->assertNotNull($products);
        $this->assertSame(
            ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'],
            $products->rangeToArray('A1:I1')[0]
        );

        // Only the header row — no leftover example data on the importable sheet.
        $this->assertSame('', trim((string) $products->getCell('A2')->getValue()));
    }

    public function test_the_template_files_products_sheet_imei_column_is_formatted_as_text(): void
    {
        $binary = $this->capture(app(ProductImportService::class)->templateResponse());

        $path = Storage::disk('local')->path('template-test-'.uniqid().'.xlsx');
        file_put_contents($path, $binary);

        $spreadsheet = IOFactory::load($path);
        $products = $spreadsheet->getSheetByName('Products');

        $this->assertSame('@', $products->getStyle('F:F')->getNumberFormat()->getFormatCode());
    }

    public function test_the_template_has_a_separate_example_sheet_with_a_worked_row(): void
    {
        $binary = $this->capture(app(ProductImportService::class)->templateResponse());

        $path = Storage::disk('local')->path('template-test-'.uniqid().'.xlsx');
        file_put_contents($path, $binary);

        $spreadsheet = IOFactory::load($path);
        $example = $spreadsheet->getSheetByName('Example');

        $this->assertNotNull($example);
        $this->assertSame('Galaxy A15', $example->getCell('A2')->getValue());
        $this->assertSame('Mobile Phone', $example->getCell('B2')->getValue());
    }

    public function test_the_importer_only_reads_the_products_sheet_ignoring_the_example_row(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        \App\Models\MainCategory::ensureDefaultsExist($shop->id);

        $binary = $this->capture(app(ProductImportService::class)->templateResponse());
        $path = Storage::disk('local')->path('template-test-'.uniqid().'.xlsx');
        file_put_contents($path, $binary);

        // The template as downloaded (Products sheet has headers only) must
        // preview as "nothing to import" rather than pulling in the
        // Example sheet's worked row.
        $preview = app(ProductImportService::class)->preview($path, $shop->id);

        $this->assertTrue($preview['valid']);
        $this->assertSame(0, $preview['toCreateCount']);
    }

    public function test_template_download_produces_a_real_file_for_an_authorized_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        Livewire::test('products.import')
            ->call('downloadTemplate')
            ->assertStatus(200);
    }
}
