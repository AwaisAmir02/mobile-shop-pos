<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleBarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(bool $barcodesEnabled = true): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => $barcodesEnabled]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function product(int $shopId, string $name, string $barcode, int $stock = 5, float $price = 500): Product
    {
        return Product::create(['shop_id' => $shopId, 'type' => 'accessory', 'name' => $name, 'price' => $price, 'stock_quantity' => $stock, 'barcode' => $barcode, 'details' => ['category' => 'Cable']]);
    }

    public function test_scanning_a_barcode_adds_its_product_to_the_cart(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015');

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet("cart.{$product->id}.quantity", 1)
            ->assertSet('search', '')
            ->assertDispatched('focus-scan');
    }

    public function test_scanning_the_same_product_twice_increments_one_line_not_a_duplicate(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015');

        $component = Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->call('scanBarcode', '2000000000015');

        $this->assertCount(1, $component->get('cart'));
        $component->assertSet("cart.{$product->id}.quantity", 2);
    }

    public function test_the_scanned_barcode_match_ignores_surrounding_whitespace_and_blank_scans_are_ignored(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015');

        Livewire::test('sales.create')
            ->call('scanBarcode', '   ')
            ->assertSet('cart', [])
            ->call('scanBarcode', '  2000000000015  ')
            ->assertSet("cart.{$product->id}.quantity", 1);
    }

    public function test_an_unknown_barcode_shows_a_not_found_message_and_adds_nothing(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'USB Cable', '2000000000015');

        Livewire::test('sales.create')
            ->call('scanBarcode', '9999999999999')
            ->assertSet('cart', [])
            ->assertDispatched('toast', type: 'error');
    }

    public function test_scanning_an_out_of_stock_product_is_refused(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015', stock: 0);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet('cart', [])
            ->assertDispatched('toast', type: 'error');
    }

    public function test_scanning_beyond_available_stock_is_refused_and_the_line_stays_at_the_limit(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015', stock: 2);

        $component = Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->call('scanBarcode', '2000000000015')
            ->call('scanBarcode', '2000000000015');

        $component->assertSet("cart.{$product->id}.quantity", 2)
            ->assertDispatched('toast', type: 'error');
    }

    public function test_a_name_search_that_matches_is_left_alone_and_adds_nothing(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'USB Cable', '2000000000015');

        Livewire::test('sales.create')
            ->set('search', 'USB')
            ->call('scanBarcode', 'USB')
            ->assertSet('cart', [])
            ->assertSet('search', 'USB')
            ->assertNotDispatched('toast');
    }

    public function test_scanned_lines_use_the_products_own_price_until_overridden(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015', price: 500);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->call('completeSale');

        $item = \App\Models\SaleItem::first();
        $this->assertSame(500.0, (float) $item->unit_price);
    }

    public function test_a_scanned_sale_completes_and_decrements_stock_once_per_scan(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'USB Cable', '2000000000015', stock: 5, price: 100);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->call('scanBarcode', '2000000000015')
            ->call('completeSale');

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame(2, \App\Models\SaleItem::first()->quantity);
    }

    public function test_scanning_is_inert_when_barcodes_are_off_for_the_shop(): void
    {
        [$shop] = $this->shopOwner(barcodesEnabled: false);
        $this->product($shop->id, 'USB Cable', '2000000000015');

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet('cart', [])
            ->assertNotDispatched('toast');
    }

    public function test_another_shops_barcode_is_never_matched(): void
    {
        [$shop] = $this->shopOwner();
        $otherShop = Shop::create(['name' => 'Shop B']);
        $this->product($otherShop->id, 'Their Cable', '2000000000015');

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet('cart', [])
            ->assertDispatched('toast', type: 'error');
    }

    public function test_the_scan_input_submits_the_typed_value_on_enter_without_relying_on_the_debounced_search(): void
    {
        $this->shopOwner();

        $this->get(route('sales.index'))
            ->assertSee('keydown.enter.prevent', false)
            ->assertSee('scanBarcode', false)
            ->assertSee('focus-scan', false);
    }

    public function test_the_scan_handler_is_inside_the_new_sale_components_boundary(): void
    {
        [$shop] = $this->shopOwner();

        $html = $this->get(route('sales.index'))->getContent();
        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        $snapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);
            if (($snapshot['memo']['name'] ?? null) === 'sales.create') {
                $snapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($snapshotOffset);
        $this->assertGreaterThan($snapshotOffset, strpos($html, 'scanBarcode'));
    }
}
