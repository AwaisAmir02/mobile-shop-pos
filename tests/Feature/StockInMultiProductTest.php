<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInMultiProductTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithTwoProducts(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $productA = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 300, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);
        $productB = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'cost_price' => 1000, 'stock_quantity' => 0, 'details' => ['category' => 'Charger']]);

        return [$shop, $owner, $productA, $productB];
    }

    public function test_a_multi_product_receipt_saves_all_lines_and_updates_each_products_stock(): void
    {
        [$shop, $owner, $productA, $productB] = $this->shopWithTwoProducts();

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '3')
            ->set('costPrice', '1000')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('stock_date', '2026-01-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5, $productA->fresh()->stock_quantity);
        $this->assertSame(3, $productB->fresh()->stock_quantity);
        $this->assertSame(2, StockIn::count());

        $entryA = StockIn::where('product_id', $productA->id)->first();
        $entryB = StockIn::where('product_id', $productB->id)->first();

        $this->assertSame(5, $entryA->quantity_remaining);
        $this->assertSame(500.0, (float) $entryA->selling_price);
        $this->assertSame(3, $entryB->quantity_remaining);
        $this->assertSame(1500.0, (float) $entryB->selling_price);
    }

    public function test_adding_the_same_product_twice_merges_into_one_line_by_summing_quantity(): void
    {
        [$shop, $owner, $productA] = $this->shopWithTwoProducts();

        $component = Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '2')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine');

        $this->assertCount(1, $component->get('cart'));
        $this->assertSame(7, $component->get('cart')[$productA->id]['quantity']);
    }

    public function test_removing_a_line_before_saving_excludes_it_entirely(): void
    {
        [$shop, $owner, $productA, $productB] = $this->shopWithTwoProducts();

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '3')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->call('removeLine', $productA->id)
            ->set('stock_date', '2026-01-10')
            ->call('save');

        $this->assertSame(0, $productA->fresh()->stock_quantity);
        $this->assertSame(3, $productB->fresh()->stock_quantity);
        $this->assertSame(1, StockIn::count());
    }

    public function test_a_partial_failure_rolls_back_the_entire_receipt(): void
    {
        [$shop, $owner, $productA, $productB] = $this->shopWithTwoProducts();

        $component = Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '3')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('stock_date', '2026-01-10');

        $productB->delete();

        try {
            $component->call('save');
            $this->fail('Expected an exception because product B no longer exists.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, $productA->fresh()->stock_quantity);
        $this->assertSame(0, StockIn::count());
    }

    public function test_the_payment_amount_is_prorated_across_lines_by_their_share_of_total_cost(): void
    {
        [$shop, $owner, $productA, $productB] = $this->shopWithTwoProducts();

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '3')
            ->set('costPrice', '1000')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '2850')
            ->set('stock_date', '2026-01-10')
            ->call('save');

        $entryA = StockIn::where('product_id', $productA->id)->first();
        $entryB = StockIn::where('product_id', $productB->id)->first();

        $this->assertSame(950.0, (float) $entryA->amount_paid);
        $this->assertSame(1900.0, (float) $entryB->amount_paid);
        $this->assertEqualsWithDelta(2850.0, (float) $entryA->amount_paid + (float) $entryB->amount_paid, 0.01);
    }

    public function test_a_multi_product_receipt_shares_the_same_vendor_date_and_note_across_lines(): void
    {
        [$shop, $owner, $productA, $productB] = $this->shopWithTwoProducts();
        $vendor = \App\Models\Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme']);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productA->id)
            ->set('quantity', '5')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '3')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('vendorId', (string) $vendor->id)
            ->set('stock_date', '2026-01-10')
            ->set('note', 'Single delivery')
            ->call('save');

        $entryA = StockIn::where('product_id', $productA->id)->first();
        $entryB = StockIn::where('product_id', $productB->id)->first();

        $this->assertSame($vendor->id, $entryA->vendor_id);
        $this->assertSame($vendor->id, $entryB->vendor_id);
        $this->assertSame('Single delivery', $entryA->note);
        $this->assertSame('Single delivery', $entryB->note);
        $this->assertSame('2026-01-10', $entryA->stock_date->toDateString());
        $this->assertSame('2026-01-10', $entryB->stock_date->toDateString());
    }

    public function test_multi_product_stock_in_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $productB = Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'X', 'price' => 100, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);

        $this->actingAs($ownerA);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '5')
            ->set('sellingPrice', '100')
            ->call('addLine')
            ->assertHasErrors(['productId']);

        $this->assertSame(0, StockIn::count());
    }
}
