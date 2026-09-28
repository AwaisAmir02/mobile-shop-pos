<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Drives the actual products.import Livewire component through all three
 * steps with a real uploaded file, rather than calling ProductImportService
 * directly (that's ProductImportServiceTest's job) — this is the glue: does
 * a real Livewire file upload actually reach the service, does the preview
 * step correctly stage state without writing, does Confirm actually create
 * the products end to end, and does the "expired upload" path degrade
 * gracefully.
 */
class ProductImportFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Livewire's Testable::set() for a file property needs an UploadedFile::fake() instance (it reads ->name); a real spreadsheet's bytes are supplied via createWithContent() so the file still parses as genuine xlsx. */
    protected function fakeUpload(array $rows): UploadedFile
    {
        $headers = ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = sys_get_temp_dir().'/'.uniqid('import_test_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('products.xlsx', $contents);
    }

    protected function authorizedShop(): Shop
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        \App\Models\MainCategory::ensureDefaultsExist($shop->id);

        return $shop;
    }

    public function test_uploading_a_valid_file_advances_to_the_preview_step_with_no_writes_yet(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10']]);

        Livewire::test('products.import')
            ->set('importFile', $file)
            ->assertSet('step', 'preview')
            ->assertSet('toCreateCount', 1);

        $this->assertSame(0, Product::count());
    }

    public function test_uploading_an_invalid_file_stays_on_the_upload_step_and_shows_errors(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        Livewire::test('products.import')
            ->set('importFile', $file)
            ->assertSet('step', 'upload')
            ->assertSee('Name is required');

        $this->assertSame(0, Product::count());
    }

    public function test_confirming_a_valid_preview_creates_the_products_and_shows_the_summary(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([
            ['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '10'],
            ['Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '25000', '20000', '2'],
        ]);

        Livewire::test('products.import')
            ->set('importFile', $file)
            ->assertSet('step', 'preview')
            ->call('confirmImport')
            ->assertSet('step', 'done')
            ->assertSet('createdCount', 2)
            ->assertSet('createdBrands', 1);

        $this->assertSame(2, Product::count());
        $this->assertTrue(Product::where('name', 'Galaxy A15')->exists());
    }

    public function test_the_uploaded_file_is_deleted_from_storage_after_a_successful_confirm(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $component = Livewire::test('products.import')->set('importFile', $file);
        $storedPath = $component->get('storedPath');

        $this->assertTrue(Storage::disk('local')->exists($storedPath));

        $component->call('confirmImport');

        $this->assertFalse(Storage::disk('local')->exists($storedPath));
    }

    public function test_confirming_with_a_missing_stored_file_shows_a_please_reupload_message_and_returns_to_upload(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $component = Livewire::test('products.import')->set('importFile', $file);
        $storedPath = $component->get('storedPath');
        Storage::disk('local')->delete($storedPath); // simulate the file having expired/vanished

        $component->call('confirmImport')
            ->assertSet('step', 'upload')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(0, Product::count());
    }

    public function test_a_double_click_on_confirm_only_imports_once(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $component = Livewire::test('products.import')->set('importFile', $file);

        // Simulate the guard being "mid-flight" by setting processing=true
        // before a second confirmImport() call — the real double-click race
        // (two overlapping network requests) can't be reproduced inside a
        // single synchronous test process, so this exercises the same guard
        // condition confirmImport() itself checks.
        $component->set('processing', true)->call('confirmImport');
        $this->assertSame(0, Product::count());

        $component->set('processing', false)->call('confirmImport');
        $this->assertSame(1, Product::count());
    }

    public function test_cancel_deletes_any_stored_file_and_resets_to_the_upload_step(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        $component = Livewire::test('products.import')->set('importFile', $file);
        $storedPath = $component->get('storedPath');

        $component->call('cancelImport')->assertSet('step', 'upload');

        $this->assertFalse(Storage::disk('local')->exists($storedPath));
    }

    /**
     * products.index listens for this via #[On('product-import-finished')]
     * to reset its pagination and pick up newly imported products on its
     * next render — separate Livewire component instances can't be driven
     * together in one test, so this confirms the event the parent relies
     * on is actually dispatched on success.
     */
    public function test_a_successful_import_dispatches_the_event_the_products_screen_listens_for(): void
    {
        $this->authorizedShop();
        $file = $this->fakeUpload([['USB Cable', 'Accessory', 'Cable', '', '', '', '500', '', '']]);

        Livewire::test('products.import')
            ->set('importFile', $file)
            ->call('confirmImport')
            ->assertDispatched('product-import-finished');
    }
}
