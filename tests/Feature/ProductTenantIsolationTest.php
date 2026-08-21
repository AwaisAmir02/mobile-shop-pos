<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_products(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        $productA = Product::create([
            'shop_id' => $shopA->id,
            'type' => 'accessory',
            'name' => 'Shop A Charger',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => ['category' => 'charger'],
        ]);

        $productB = Product::create([
            'shop_id' => $shopB->id,
            'type' => 'accessory',
            'name' => 'Shop B Charger',
            'price' => 500,
            'stock_quantity' => 10,
            'details' => ['category' => 'charger'],
        ]);

        $this->actingAs($userA);

        Livewire::test('products.index')
            ->assertSee('Shop A Charger')
            ->assertDontSee('Shop B Charger');

        $this->assertNull(
            Product::find($productB->id),
            'A shop must never be able to load another shop\'s product via the global scope.'
        );

        $this->actingAs($userB);

        $this->assertNull(Product::find($productA->id));
        $this->assertNotNull(Product::find($productB->id));
    }

    public function test_creating_a_product_auto_assigns_the_authenticated_users_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $product = Product::create([
            'type' => 'accessory',
            'name' => 'Auto Scoped Product',
            'price' => 250,
            'stock_quantity' => 5,
            'details' => ['category' => 'cable'],
        ]);

        $this->assertSame($shop->id, $product->shop_id);
    }
}
