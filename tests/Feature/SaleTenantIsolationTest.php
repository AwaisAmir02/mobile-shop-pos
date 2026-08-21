<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_sales(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        $saleA = Sale::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);

        Sale::create([
            'shop_id' => $shopB->id,
            'user_id' => $userB->id,
            'subtotal' => 2000,
            'discount_amount' => 0,
            'total' => 2000,
        ]);

        $this->actingAs($userA);

        Livewire::test('sales.history')
            ->assertSee('Rs 1,000.00')
            ->assertDontSee('Rs 2,000.00');

        $this->get(route('sales.show', $saleA))->assertOk();
    }

    public function test_completing_a_sale_decrements_stock_and_scopes_to_the_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Test Cable',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => ['category' => 'cable'],
        ]);

        $this->actingAs($user);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 3)
            ->call('completeSale');

        $this->assertSame(7, $product->fresh()->stock_quantity);

        $sale = Sale::first();
        $this->assertSame($shop->id, $sale->shop_id);
        $this->assertEquals(1500, $sale->total);
    }
}
