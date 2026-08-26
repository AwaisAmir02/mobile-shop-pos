<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesCustomerLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_correctly_links_to_the_selected_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Cable',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => [],
        ]);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->call('completeSale');

        $sale = Sale::first();
        $this->assertSame($customer->id, $sale->customer_id);
    }

    public function test_a_sale_remains_customer_less_when_none_is_selected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Cable',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => [],
        ]);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->call('completeSale');

        $sale = Sale::first();
        $this->assertNull($sale->customer_id);
    }

    public function test_a_tampered_customer_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Bilal Ahmed']);
        $product = Product::create([
            'shop_id' => $shopA->id,
            'type' => 'accessory',
            'name' => 'Cable',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => [],
        ]);

        $this->actingAs($ownerA);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customerB->id)
            ->call('addToCart', $product->id)
            ->call('completeSale')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, Sale::count());
    }

    public function test_customer_profile_shows_only_that_customers_sales_and_udhaar(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customerA = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $customerB = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Ahmed']);

        Sale::create(['shop_id' => $shop->id, 'customer_id' => $customerA->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);
        Sale::create(['shop_id' => $shop->id, 'customer_id' => $customerB->id, 'subtotal' => 5000, 'discount_amount' => 0, 'total' => 5000]);

        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customerA->id, 'type' => 'given', 'amount' => 300, 'transaction_date' => '2026-08-01']);
        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customerB->id, 'type' => 'given', 'amount' => 9999, 'transaction_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('customers.show', ['customer' => $customerA])
            ->assertSee('Rs 1,000.00')
            ->assertDontSee('Rs 5,000.00')
            ->assertSee('Rs 300.00')
            ->assertDontSee('9,999.00');
    }

    public function test_customer_profile_never_leaks_another_shops_data(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Bilal Ahmed']);

        $this->actingAs($ownerA);

        $this->get(route('customers.show', $customerB))->assertNotFound();
    }

    public function test_sales_history_shows_walk_in_for_sales_without_a_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        Sale::create(['shop_id' => $shop->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);

        $this->actingAs($owner);

        Livewire::test('sales.history')->assertSee('Walk-in');
    }
}
