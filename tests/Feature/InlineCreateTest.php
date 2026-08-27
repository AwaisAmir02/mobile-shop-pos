<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InlineCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_customer_inline_from_sales_creates_a_real_customer_and_selects_it(): void
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

        $sales = Livewire::test('sales.create');
        $quickCreate = Livewire::test('customers.quick-create');

        $quickCreate
            ->set('name', 'Inline Customer')
            ->set('phone', '03001234567')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Inline Customer')->firstOrFail();
        $this->assertSame($shop->id, $customer->shop_id);
        $this->assertSame('03001234567', $customer->phone);

        // Simulate the Sales screen receiving the dispatched event, as it would live.
        $sales->call('onCustomerCreated', $customer->id);
        $sales->assertSet('customerId', (string) $customer->id);

        $sales
            ->call('addToCart', $product->id)
            ->call('completeSale');

        $sale = Sale::first();
        $this->assertSame($customer->id, $sale->customer_id);
    }

    public function test_selecting_the_new_customer_option_opens_the_quick_create_modal_and_resets_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    public function test_creating_an_accessory_category_inline_from_add_product_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $products = Livewire::test('products.index');
        $quickCreate = Livewire::test('accessory-categories.quick-create');

        $quickCreate
            ->set('name', 'Tempered Glass')
            ->call('save')
            ->assertHasNoErrors();

        $category = AccessoryCategoryOption::where('name', 'Tempered Glass')->firstOrFail();
        $this->assertSame($shop->id, $category->shop_id);

        $products->call('onAccessoryCategoryCreated', 'Tempered Glass');
        $products->assertSet('category', 'Tempered Glass');

        $products
            ->set('type', 'accessory')
            ->set('name', 'Screen Guard')
            ->set('price', '300')
            ->set('stock_quantity', '5')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Screen Guard')->firstOrFail();
        $this->assertSame('Tempered Glass', $product->details['category']);
    }

    public function test_the_quick_create_category_form_enforces_the_same_uniqueness_rule_as_settings(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'name' => 'Charger']);

        $this->actingAs($owner);

        Livewire::test('accessory-categories.quick-create')
            ->set('name', 'Charger')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertSame(1, AccessoryCategoryOption::where('name', 'Charger')->count());
    }

    public function test_inline_created_customers_and_categories_are_tenant_scoped(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $this->actingAs($ownerA);

        Livewire::test('customers.quick-create')
            ->set('name', 'Shop A Customer')
            ->call('save');

        Livewire::test('accessory-categories.quick-create')
            ->set('name', 'Shop A Category')
            ->call('save');

        $customer = Customer::where('name', 'Shop A Customer')->firstOrFail();
        $category = AccessoryCategoryOption::where('name', 'Shop A Category')->firstOrFail();

        $this->assertSame($shopA->id, $customer->shop_id);
        $this->assertSame($shopA->id, $category->shop_id);
    }
}
