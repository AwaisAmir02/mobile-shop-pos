<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInPaymentAndCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    // ── Category filter ──────────────────────────────────────────────

    public function test_selecting_a_category_filters_the_product_dropdown(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $mobile = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'stock_quantity' => 0, 'details' => []]);
        $accessory = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('categoryFilter', 'accessory')
            ->assertViewHas('products', function ($products) use ($accessory, $mobile) {
                return $products->pluck('id')->contains($accessory->id)
                    && ! $products->pluck('id')->contains($mobile->id);
            });
    }

    public function test_changing_category_resets_the_selected_product(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $accessory->id)
            ->set('categoryFilter', 'mobile')
            ->assertSet('productId', '');
    }

    // ── Payment status ────────────────────────────────────────────────

    public function test_a_stock_in_defaults_to_paid_and_owes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'cost_price' => 1000, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('stock_date', '2026-08-01')
            ->call('save')
            ->assertHasNoErrors();

        $entry = StockIn::firstOrFail();
        $this->assertSame('paid', $entry->payment_status->value);
        $this->assertEquals(10000, $entry->total_cost);
        $this->assertEquals(10000, $entry->amount_paid);
        $this->assertEquals(0, $entry->amountOwed());
    }

    public function test_an_unpaid_stock_in_owes_the_full_total_cost(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'cost_price' => 1000, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('paymentStatus', 'unpaid')
            ->set('stock_date', '2026-08-01')
            ->call('save')
            ->assertHasNoErrors();

        $entry = StockIn::firstOrFail();
        $this->assertSame('unpaid', $entry->payment_status->value);
        $this->assertEquals(0, $entry->amount_paid);
        $this->assertEquals(10000, $entry->amountOwed());
    }

    public function test_a_partial_stock_in_requires_and_stores_the_amount_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'cost_price' => 1000, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '4000')
            ->set('stock_date', '2026-08-01')
            ->call('save')
            ->assertHasNoErrors();

        $entry = StockIn::firstOrFail();
        $this->assertSame('partial', $entry->payment_status->value);
        $this->assertEquals(4000, $entry->amount_paid);
        $this->assertEquals(6000, $entry->amountOwed());
    }

    public function test_partial_without_an_amount_paid_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'cost_price' => 1000, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('paymentStatus', 'partial')
            ->set('stock_date', '2026-08-01')
            ->call('save')
            ->assertHasErrors(['amountPaid']);

        $this->assertSame(0, StockIn::count());
    }

    // ── Outstanding total & payment actions ─────────────────────────

    public function test_total_owed_to_suppliers_sums_unpaid_and_partial_entries_only(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'A', 'quantity' => 1, 'total_cost' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'B', 'quantity' => 1, 'total_cost' => 2000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'C', 'quantity' => 1, 'total_cost' => 3000, 'payment_status' => 'partial', 'amount_paid' => 1000, 'stock_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('stock-ins.history')
            ->assertViewHas('totalOwed', 4000.0);
    }

    public function test_marking_a_stock_in_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $entry = StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'A', 'quantity' => 1, 'total_cost' => 2000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('stock-ins.history')->call('markAsPaid', $entry->id);

        $entry->refresh();
        $this->assertSame('paid', $entry->payment_status->value);
        $this->assertEquals(0, $entry->amountOwed());
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $entry = StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'A', 'quantity' => 1, 'total_cost' => 5000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('stock-ins.history')
            ->call('openAddPayment', $entry->id)
            ->set('paymentAmount', '2000')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame('partial', $entry->payment_status->value);
        $this->assertEquals(2000, $entry->amount_paid);
        $this->assertEquals(3000, $entry->amountOwed());

        Livewire::test('stock-ins.history')
            ->call('openAddPayment', $entry->id)
            ->set('paymentAmount', '3000')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame('paid', $entry->payment_status->value);
        $this->assertEquals(5000, $entry->amount_paid);
        $this->assertEquals(0, $entry->amountOwed());
    }

    public function test_the_history_screen_can_filter_by_payment_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'Paid Product', 'quantity' => 1, 'total_cost' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'Unpaid Product', 'quantity' => 1, 'total_cost' => 2000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('stock-ins.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('Unpaid Product')
            ->assertDontSee('Paid Product');
    }

    // ── Tenant isolation ──────────────────────────────────────────────

    public function test_total_owed_to_suppliers_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        StockIn::create(['shop_id' => $shopA->id, 'product_id' => null, 'product_name' => 'A', 'quantity' => 1, 'total_cost' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shopB->id, 'product_id' => null, 'product_name' => 'B', 'quantity' => 1, 'total_cost' => 9999, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01']);

        $this->actingAs($ownerA);

        Livewire::test('stock-ins.history')
            ->assertViewHas('totalOwed', 1000.0);
    }
}
