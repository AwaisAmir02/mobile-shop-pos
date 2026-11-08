<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Design overhaul D4: the Sales register's quick-pick tiles, its controls, and
 * the "sale complete" confirmation. Selling itself is covered by the existing
 * Sale* tests; these only check what D4 added.
 */
class SalesRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function product(Shop $shop, string $name, int $stock = 20, float $price = 500): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'details' => [],
        ]);
    }

    private function sell(Product $product, int $quantity): Sale
    {
        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", $quantity)
            ->call('completeSale')
            ->assertHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    /** @return list<int> product ids of the tiles, in order */
    private function tiles($component): array
    {
        preg_match_all('/data-quick-pick="(\d+)"/', $component->html(), $matches);

        return array_map('intval', $matches[1]);
    }

    public function test_quick_picks_show_this_months_best_sellers_in_order(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $charger = $this->product($shop, 'Charger');
        $cable = $this->product($shop, 'Cable');
        $case = $this->product($shop, 'Case');
        $this->product($shop, 'Never sold');

        $this->sell($cable, 1);
        $this->sell($charger, 5);
        $this->sell($case, 3);

        $this->assertSame([$charger->id, $case->id, $cable->id], $this->tiles(Livewire::test('sales.create')));
    }

    public function test_quick_picks_leave_out_stock_that_ran_out_and_cancelled_sales(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $soldOut = $this->product($shop, 'Last one', stock: 1);
        $cancelled = $this->product($shop, 'Returned item');
        $kept = $this->product($shop, 'Kept item');

        $this->sell($soldOut, 1);
        $this->sell($kept, 1);
        $sale = $this->sell($cancelled, 4);

        Livewire::test('sales.history')->call('openCancel', $sale->id)->call('cancelSale')->assertHasNoErrors();

        $this->assertSame([$kept->id], $this->tiles(Livewire::test('sales.create')));
    }

    public function test_quick_picks_never_show_another_shops_products(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs(User::factory()->create(['shop_id' => $shopB->id]));
        $theirs = $this->product($shopB, 'Shop B bestseller');
        $this->sell($theirs, 5);

        $this->actingAs(User::factory()->create(['shop_id' => $shopA->id]));
        $component = Livewire::test('sales.create');

        $this->assertSame([], $this->tiles($component));
        $component->assertDontSee('Shop B bestseller');
    }

    public function test_quick_picks_give_way_to_the_cart_and_to_search(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        $product = $this->product($shop, 'Charger');
        $this->sell($product, 2);

        $register = Livewire::test('sales.create');
        $this->assertSame([$product->id], $this->tiles($register));
        $register->assertSeeHtml('wire:click="addToCart('.$product->id.')"');

        $this->assertSame([], $this->tiles($register->set('search', 'Char')));
        $this->assertSame([], $this->tiles($register->set('search', '')->call('addToCart', $product->id)));
    }

    public function test_the_register_offers_payment_modes_shortcuts_and_shows_the_amount_on_the_button(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        $product = $this->product($shop, 'Charger', price: 1800);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSeeHtml('data-payment-mode="cash"')
            ->assertSeeHtml('data-payment-mode="partial"')
            ->assertSeeHtml('data-payment-mode="udhaar"')
            ->assertSeeHtml('x-on:keydown.window.f9.prevent="$wire.completeSale()"')
            ->assertSeeHtml('x-on:keydown.window.f2.prevent')
            ->assertSee('Complete Sale')
            ->assertSeeHtml('class="tabular-nums">Rs 1,800.00</span>');
    }

    public function test_the_receipt_opens_as_a_confirmation_only_straight_after_the_sale(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        $product = $this->product($shop, 'Charger', price: 1800);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->call('completeSale')
            ->assertRedirect();
        $sale = Sale::firstOrFail();

        // Completing the sale leaves a one-time marker for the receipt...
        $this->assertSame($sale->id, session('sale-completed'));

        // ...which opens it as a confirmation with the next sale ready.
        Livewire::test('sales.show', ['sale' => $sale])
            ->assertSet('justCompleted', true)
            ->assertSee('Sale complete')
            ->assertSee('Paid in full')
            ->assertSeeHtml('data-next-sale');

        // Any other visit is the plain receipt.
        session()->forget('sale-completed');

        Livewire::test('sales.show', ['sale' => $sale])
            ->assertSet('justCompleted', false)
            ->assertDontSee('Sale complete')
            ->assertDontSeeHtml('data-next-sale');

        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee('Sale complete');
    }
}
