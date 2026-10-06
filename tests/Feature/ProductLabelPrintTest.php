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

class ProductLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(bool $barcodesEnabled = true): array
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop', 'barcode_enabled' => $barcodesEnabled]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_a_roll_label_pdf_is_generated_as_a_real_pdf_file(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        $response = app(ProductLabelPdfService::class)->download($product, 2, ProductLabelPdfService::LAYOUT_ROLL, true, $shop->name);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringStartsWith('%PDF', $this->capture($response));
    }

    public function test_an_a4_sheet_pdf_is_generated_for_more_copies_than_one_page_holds(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        $response = app(ProductLabelPdfService::class)->download($product, 30, ProductLabelPdfService::LAYOUT_A4, false, $shop->name);

        $this->assertStringStartsWith('%PDF', $this->capture($response));
    }

    public function test_the_label_markup_has_the_barcode_number_shop_name_and_optional_price(): void
    {
        $withPrice = view('pdf.partials.product-label-content', ['label' => [
            'shopName' => 'Zain Mobile Shop', 'name' => 'USB Cable', 'barcode' => '2000000000015',
            'barcodeImage' => BarcodeService::imageDataUri('2000000000015'), 'price' => 'Rs 500.00',
        ]])->render();

        $this->assertStringContainsString('Zain Mobile Shop', $withPrice);
        $this->assertStringContainsString('2000000000015', $withPrice);
        $this->assertStringContainsString('Rs 500.00', $withPrice);
        $this->assertStringContainsString('data:image/png;base64,', $withPrice);

        $withoutPrice = view('pdf.partials.product-label-content', ['label' => [
            'shopName' => 'Zain Mobile Shop', 'name' => 'USB Cable', 'barcode' => '2000000000015',
            'barcodeImage' => BarcodeService::imageDataUri('2000000000015'), 'price' => null,
        ]])->render();

        $this->assertStringNotContainsString('Rs ', $withoutPrice);
    }

    public function test_the_print_action_refuses_more_than_the_per_request_cap(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        Livewire::test('products.index')
            ->call('openPrintLabel', $product->id)
            ->set('printCopies', (string) (BarcodeService::MAX_LABEL_COPIES + 1))
            ->call('printLabels')
            ->assertHasErrors(['printCopies']);
    }

    public function test_the_print_action_refuses_zero_or_negative_copies(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        Livewire::test('products.index')
            ->call('openPrintLabel', $product->id)
            ->set('printCopies', '0')
            ->call('printLabels')
            ->assertHasErrors(['printCopies']);
    }

    public function test_printing_at_exactly_the_cap_is_allowed(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        Livewire::test('products.index')
            ->call('openPrintLabel', $product->id)
            ->set('printCopies', (string) BarcodeService::MAX_LABEL_COPIES)
            ->call('printLabels')
            ->assertHasNoErrors();
    }

    public function test_a_product_without_a_barcode_cannot_be_printed_until_one_is_generated(): void
    {
        [$shop] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Plain', 'price' => 500, 'details' => []]);

        Livewire::test('products.index')
            ->call('openPrintLabel', $product->id)
            ->call('printLabels')
            ->assertDispatched('toast', type: 'error');

        Livewire::test('products.index')
            ->call('generateBarcodeFor', $product->id);

        $this->assertNotNull($product->fresh()->barcode);
    }

    public function test_the_print_flow_is_refused_when_barcodes_are_off_for_the_shop(): void
    {
        [$shop] = $this->shopOwner(barcodesEnabled: false);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        Livewire::test('products.index')
            ->call('openPrintLabel', $product->id)
            ->assertNotDispatched('open-modal', name: 'print-label-form');
    }

    public function test_the_print_label_button_is_rendered_for_a_barcode_enabled_shop(): void
    {
        [$shop] = $this->shopOwner();
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'barcode' => '2000000000015', 'details' => []]);

        $this->get(route('products.index'))->assertSee('Print Label');
    }
}
