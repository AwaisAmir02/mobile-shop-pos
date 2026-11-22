<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\UpdateSale;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2: an outer pack on top of a pack (a box of 3 strips of 10 tablets), sold
 * and bought whole, and the pack each sale line was sold in recorded on it.
 */
class PackLevelsTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Shifa Medical', 'business_category' => 'pharmacy']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->actingAs($this->owner);
        MainCategory::ensureDefaultsExist($this->shop->id);
        MainCategory::create(['shop_id' => $this->shop->id, 'name' => 'Medicines', 'slug' => 'medicines']);
    }

    /** Panadol: tablets at Rs 3, a strip of 10 at Rs 28, a box of 3 strips at Rs 80. */
    protected function panadol(array $extra = [], int $stock = 0): Product
    {
        $product = Product::create($extra + [
            'shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Panadol 500mg', 'price' => 3, 'cost_price' => 2, 'stock_quantity' => 0,
            'pack_name' => 'Strip', 'pack_size' => 10, 'pack_price' => 28,
            'outer_pack_name' => 'Box', 'outer_pack_size' => 3, 'outer_pack_price' => 80,
            'details' => ['category' => null],
        ]);

        if ($stock > 0) {
            CreateStockIn::handle([['product_id' => $product->id, 'quantity' => $stock, 'cost_price' => 2, 'selling_price' => 3]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
        }

        return $product->fresh();
    }

    public function test_an_outer_pack_holds_packs_and_needs_a_pack_under_it(): void
    {
        $product = $this->panadol();

        $this->assertTrue($product->hasOuterPack());
        $this->assertSame(30, $product->outerPackPieces());
        $this->assertSame(80.0, $product->outerPackSellingPrice());
        $this->assertSame('Box of 3 strips (30)', $product->outerPackDescription());
        $this->assertSame(84.0, $this->panadol(['name' => 'B', 'outer_pack_price' => null])->outerPackSellingPrice(), 'three strips at the strip price');
        $this->assertFalse($this->panadol(['name' => 'C', 'pack_name' => null, 'pack_size' => null])->hasOuterPack());
    }

    public function test_the_product_form_sets_an_outer_pack_and_drops_it_with_the_pack(): void
    {
        $product = Product::create(['shop_id' => $this->shop->id, 'type' => 'medicines', 'name' => 'Brufen', 'price' => 5, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->set('pack_name', 'Strip')->set('pack_size', '10')
            ->assertSeeHtml('data-outer-pack-fields')
            ->set('outer_pack_name', 'Box')->set('outer_pack_size', '2')->set('outer_pack_price', '95')
            ->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertSame(['Box', 2, '95.00'], [$product->outer_pack_name, $product->outer_pack_size, $product->outer_pack_price]);

        Livewire::test('products.index')->call('openEdit', $product->id)->set('pack_name', '')->call('save')->assertHasNoErrors();
        $this->assertNull($product->fresh()->outer_pack_name);

        Livewire::test('products.index')->call('openEdit', $product->id)->set('pack_name', 'Strip')->set('pack_size', '10')
            ->set('outer_pack_name', 'Box')->set('outer_pack_size', '')->call('save')->assertHasErrors('outer_pack_size');
    }

    public function test_the_register_sells_a_whole_box_at_its_price_and_the_sale_line_records_it(): void
    {
        $product = $this->panadol(stock: 100);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSee('Box of 3')
            ->call('sellAs', $product->id, 'outer')
            ->assertSet("cart.{$product->id}.quantity", 1)
            ->assertSet("cart.{$product->id}.max_stock", 3)
            ->call('addToCart', $product->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertEquals(160, (float) $sale->total);
        $item = $sale->items()->sole();
        $this->assertSame(60, $item->quantity);
        $this->assertSame(['Box', 30], [$item->pack_name, $item->pack_size]);
        $this->assertSame('2 boxes', $item->packLabel());
        $this->assertSame(40, $product->fresh()->stock_quantity);
    }

    public function test_switching_between_pieces_strips_and_boxes_converts_the_quantity(): void
    {
        $product = $this->panadol(stock: 100);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 30)
            ->call('sellAs', $product->id, 'pack')->assertSet("cart.{$product->id}.quantity", 3)
            ->call('sellAs', $product->id, 'outer')->assertSet("cart.{$product->id}.quantity", 1)
            ->call('sellAs', $product->id, 'piece')->assertSet("cart.{$product->id}.quantity", 30)
            ->call('sellAs', $product->id, 'pack')
            ->set("cart.{$product->id}.quantity", 2)
            ->call('completeSale')->assertHasNoErrors();

        $item = Sale::sole()->items()->sole();
        $this->assertSame(['Strip', 10, '2 strips'], [$item->pack_name, $item->pack_size, $item->packLabel()]);
        $this->assertEquals(56, (float) Sale::sole()->total);
    }

    public function test_loose_pieces_record_no_pack_and_old_sales_read_as_before(): void
    {
        $product = $this->panadol(stock: 100);

        Livewire::test('sales.create')->call('addToCart', $product->id)->call('completeSale')->assertHasNoErrors();

        $item = Sale::sole()->items()->sole();
        $this->assertNull($item->pack_name);
        $this->assertNull($item->packLabel());
    }

    public function test_a_box_split_across_two_batches_still_totals_exactly(): void
    {
        $product = $this->panadol();
        CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 25, 'cost_price' => 2, 'selling_price' => 3]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
        CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 25, 'cost_price' => 2.2, 'selling_price' => 3]], null, 'paid', null, '2026-10-02', null, $this->owner->id);

        Livewire::test('sales.create')->call('addToCart', $product->id)->call('sellAs', $product->id, 'outer')->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertEquals(80, (float) $sale->total);
        $this->assertSame([25, 5], $sale->items()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(['part of a box sale', 'part of a box sale'], $sale->items()->orderBy('id')->get()->map->packLabel()->all());
    }

    public function test_a_pack_that_does_not_divide_the_quantity_is_not_recorded(): void
    {
        $product = $this->panadol(stock: 100);

        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 15, 'discount' => 0.0, 'custom_price' => null, 'pack_name' => 'Strip', 'pack_size' => 10]], 0.0, null, $this->owner->id, now()->toDateString(), 45);

        $this->assertNull($sale->items()->sole()->pack_name);
    }

    public function test_an_edit_keeps_the_pack_while_the_line_is_still_whole_packs(): void
    {
        $product = $this->panadol(stock: 100);
        $customer = \App\Models\Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha']);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 20, 'discount' => 0.0, 'custom_price' => 2.8, 'pack_name' => 'Strip', 'pack_size' => 10]], 0.0, $customer->id, $this->owner->id, now()->toDateString(), 0);

        $line = fn ($quantity) => [['product_id' => $product->id, 'product_name' => $product->name, 'product_type' => 'accessory', 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => 2.8]];

        UpdateSale::handle($sale, $line(30), 0.0, $customer->id, $this->owner->id, now()->toDateString());
        $this->assertSame('3 strips', $sale->fresh()->items()->sole()->packLabel());

        UpdateSale::handle($sale->fresh(), $line(25), 0.0, $customer->id, $this->owner->id, now()->toDateString());
        $this->assertNull($sale->fresh()->items()->sole()->pack_name);
    }

    public function test_stock_in_buys_whole_boxes_by_default_with_an_exact_total(): void
    {
        $product = $this->panadol();

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->assertSet('buyIn', 'outer')
            ->assertSet('costPrice', '60')
            ->assertSee('By the box (30)')
            ->set('buyIn', 'pack')->assertSet('costPrice', '20')
            ->set('buyIn', 'outer')->assertSet('costPrice', '60')
            ->set('quantity', '2')->set('costPrice', '55')->set('sellingPrice', '3')
            ->call('addLine')
            ->call('save');

        $batch = StockIn::sole();
        $this->assertSame(60, $batch->quantity);
        $this->assertSame('110.00', $batch->total_cost);
        $this->assertSame(['Box', 30, 2], [$batch->pack_name, $batch->pack_size, $batch->packs]);
        $this->assertSame(60, $product->fresh()->stock_quantity);
    }

    public function test_the_receipt_and_the_return_screen_speak_in_packs(): void
    {
        $product = $this->panadol(stock: 100);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 30, 'discount' => 0.0, 'custom_price' => 2.8, 'pack_name' => 'Strip', 'pack_size' => 10]], 0.0, null, $this->owner->id, now()->toDateString(), 84);

        Livewire::test('sales.show', ['sale' => $sale])->assertSee('(3 strips)');
        Livewire::test('sales.return', ['sale' => $sale])->assertSee('(3 strips)')->assertSee('1 strip = 10');
    }
}
