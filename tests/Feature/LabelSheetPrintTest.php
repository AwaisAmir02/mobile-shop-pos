<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\BarcodeService;
use App\Services\ProductLabelPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class LabelSheetPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(bool $barcodesEnabled = true): array
    {
        $shop = Shop::create(['name' => 'Madina Store', 'barcode_enabled' => $barcodesEnabled]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function product(Shop $shop, string $name, ?string $barcode): Product
    {
        return Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => $name, 'price' => 250, 'stock_quantity' => 5, 'barcode' => $barcode, 'details' => ['category' => 'Cable']]);
    }

    protected function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    protected function label(string $name, string $barcode): array
    {
        return ['shopName' => 'Madina Store', 'name' => $name, 'barcode' => $barcode, 'barcodeImage' => BarcodeService::imageDataUri($barcode), 'price' => null];
    }

    public function test_the_existing_single_product_view_still_prints_exactly_n_copies(): void
    {
        foreach (['roll', 'a4'] as $layout) {
            $html = view('pdf.product-label', ['layout' => $layout, 'copies' => 5, 'columns' => 3, 'rowsPerPage' => 8, 'label' => $this->label('Rice', '2000000000015')])->render();

            $this->assertSame(5, substr_count($html, 'class="number">2000000000015<'), $layout);
            $this->assertStringContainsString('<title>2000000000015</title>', $html);
        }
    }

    public function test_a_sheet_repeats_each_product_by_its_own_copies(): void
    {
        $labels = [...array_fill(0, 2, $this->label('Rice', '2000000000015')), ...array_fill(0, 3, $this->label('Sugar', '2000000000022'))];

        $html = view('pdf.product-label', ['layout' => 'a4', 'copies' => 5, 'columns' => 3, 'rowsPerPage' => 8, 'label' => $labels[0], 'labels' => $labels, 'title' => 'Product labels'])->render();

        $this->assertSame(2, substr_count($html, 'class="number">2000000000015<'));
        $this->assertSame(3, substr_count($html, 'class="number">2000000000022<'));
        $this->assertSame(1, substr_count($html, '<table class="sheet'));
    }

    public function test_the_service_builds_a_real_multi_page_pdf(): void
    {
        [$shop] = $this->shopOwner();
        $rice = $this->product($shop, 'Rice', '2000000000015');
        $sugar = $this->product($shop, 'Sugar', '2000000000022');

        $response = app(ProductLabelPdfService::class)->downloadSheet([['product' => $rice, 'copies' => 20], ['product' => $sugar, 'copies' => 10]], true, $shop->name);

        $this->assertStringStartsWith('%PDF', $this->capture($response));
    }

    public function test_an_owner_selects_products_and_downloads_one_sheet(): void
    {
        [$shop] = $this->shopOwner();
        $rice = $this->product($shop, 'Rice', '2000000000015');
        $sugar = $this->product($shop, 'Sugar', '2000000000022');
        $loose = $this->product($shop, 'Loose daal', null);
        $this->travelTo(now()->setDateTime(2026, 11, 2, 10, 30, 0));

        $component = Livewire::test('products.index')
            ->assertSeeHtml('wire:model.live="labelSelection"')
            ->set('labelSelection', [(string) $rice->id, (string) $sugar->id, (string) $loose->id])
            ->assertSee('3 products selected for labels')
            ->call('openLabelSheet')
            ->assertSee('has no barcode and will be skipped')
            ->set("sheetCopies.{$rice->id}", '4')
            ->call('printLabelSheet')
            ->assertHasNoErrors()
            ->assertFileDownloaded('labels-20261102-103000.pdf');

        $component->assertSet('labelSelection', []);
        $this->assertNull($loose->fresh()->barcode);
    }

    public function test_missing_barcodes_can_be_generated_for_the_selection(): void
    {
        [$shop] = $this->shopOwner();
        $loose = $this->product($shop, 'Loose daal', null);
        $other = $this->product($shop, 'Not selected', null);

        Livewire::test('products.index')->set('labelSelection', [(string) $loose->id])->call('openLabelSheet')->call('generateBarcodesForSelection');

        $this->assertTrue(BarcodeService::isEan13($loose->fresh()->barcode));
        $this->assertNull($other->fresh()->barcode);
    }

    public function test_copies_and_totals_are_capped(): void
    {
        [$shop] = $this->shopOwner();
        $rice = $this->product($shop, 'Rice', '2000000000015');
        $sugar = $this->product($shop, 'Sugar', '2000000000022');

        Livewire::test('products.index')->set('labelSelection', [(string) $rice->id])->call('openLabelSheet')
            ->set("sheetCopies.{$rice->id}", '0')->call('printLabelSheet')->assertHasErrors(['sheetCopies.'.$rice->id])->assertNoFileDownloaded();

        Livewire::test('products.index')->set('labelSelection', [(string) $rice->id, (string) $sugar->id])->call('openLabelSheet')
            ->set("sheetCopies.{$rice->id}", '150')->set("sheetCopies.{$sugar->id}", '51')
            ->call('printLabelSheet')->assertHasErrors(['sheetCopies'])->assertNoFileDownloaded();

        Livewire::test('products.index')->set('labelSelection', [(string) $rice->id, (string) $sugar->id])->call('openLabelSheet')
            ->set("sheetCopies.{$rice->id}", '150')->set("sheetCopies.{$sugar->id}", '50')
            ->call('printLabelSheet')->assertHasNoErrors();
    }

    public function test_empty_or_oversized_selections_and_barcode_less_sheets_are_refused(): void
    {
        [$shop] = $this->shopOwner();
        $loose = $this->product($shop, 'Loose daal', null);

        Livewire::test('products.index')->call('openLabelSheet')->assertNotDispatched('open-modal');

        $ids = collect(range(1, 101))->map(fn ($i) => (string) $this->product($shop, "Item {$i}", null)->id)->all();
        Livewire::test('products.index')->set('labelSelection', $ids)->call('openLabelSheet')->assertNotDispatched('open-modal');

        Livewire::test('products.index')->set('labelSelection', [(string) $loose->id])->call('openLabelSheet')->call('printLabelSheet')->assertNoFileDownloaded();
    }

    public function test_another_shops_products_never_reach_the_sheet(): void
    {
        [$shopA] = $this->shopOwner();
        $mine = $this->product($shopA, 'Mine', '2000000000015');
        $shopB = Shop::create(['name' => 'Other', 'barcode_enabled' => true]);
        $theirs = Product::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Theirs', 'price' => 1, 'barcode' => '2000000000022', 'details' => []]);

        Livewire::test('products.index')->set('labelSelection', [(string) $mine->id, (string) $theirs->id])->call('openLabelSheet')
            ->assertSee('Mine')->assertDontSee('Theirs')
            ->call('generateBarcodesForSelection');

        $this->assertSame('2000000000022', Product::withoutGlobalScopes()->find($theirs->id)->barcode);
    }

    public function test_shops_without_barcodes_get_no_selection_or_sheet(): void
    {
        [$shop] = $this->shopOwner(barcodesEnabled: false);
        $this->product($shop, 'Rice', null);

        Livewire::test('products.index')->assertDontSeeHtml('wire:model.live="labelSelection"')->call('openLabelSheet')->assertForbidden();
    }
}
