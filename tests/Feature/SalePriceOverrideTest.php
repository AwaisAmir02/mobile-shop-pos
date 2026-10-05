<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalePriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    public function test_leaving_the_price_blank_uses_the_products_own_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->call('completeSale');

        $item = SaleItem::first();
        $this->assertSame(500.0, (float) $item->unit_price);
        $this->assertSame(500.0, (float) $item->list_price);
        $this->assertFalse($item->wasPriceOverridden());
    }

    public function test_filling_the_price_overrides_the_line_and_is_what_gets_saved(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '600')
            ->call('completeSale');

        $item = SaleItem::first();
        $this->assertSame(600.0, (float) $item->unit_price);
        $this->assertSame(500.0, (float) $item->list_price);
        $this->assertTrue($item->wasPriceOverridden());

        $sale = Sale::first();
        $this->assertSame(600.0, (float) $sale->total);
    }

    public function test_the_products_own_stored_price_is_never_modified_by_a_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '600')
            ->call('completeSale');

        $this->assertSame(500.0, (float) $product->fresh()->price);
    }

    public function test_a_negative_or_oversized_price_is_rejected(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '-10')
            ->call('completeSale')
            ->assertHasErrors(["cart.{$product->id}.custom_price"]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '99999999')
            ->call('completeSale')
            ->assertHasErrors(["cart.{$product->id}.custom_price"]);

        $this->assertSame(0, Sale::count());
    }

    public function test_the_line_discount_still_applies_on_top_of_an_overridden_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '600')
            ->set("cart.{$product->id}.discount", '50')
            ->call('completeSale');

        $item = SaleItem::first();
        $this->assertSame(550.0, (float) $item->line_total);
    }

    public function test_a_price_override_survives_a_later_change_to_the_products_own_price_unchanged_on_the_invoice(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '600')
            ->call('completeSale');

        $sale = Sale::with('items')->first();

        // The shop later changes the product's own list price — the historical sale must not move.
        $product->update(['price' => 9999]);

        $sale->refresh();
        $item = $sale->items->first();
        $this->assertSame(600.0, (float) $item->unit_price);
        $this->assertSame(500.0, (float) $item->list_price);
        $this->assertSame(600.0, (float) $sale->total);
    }

    public function test_price_overridden_hint_renders_on_the_invoice(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 500, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.custom_price", '600')
            ->call('completeSale');

        $sale = Sale::with(['items', 'shop', 'user', 'payments'])->first();
        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('overridden', $html);
    }
}
