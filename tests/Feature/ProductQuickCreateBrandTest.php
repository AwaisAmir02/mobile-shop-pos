<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Stock In screen's "quick create product" modal (products.quick-create)
 * shares CreateProduct::rules()/detailsForType() with the main Add Product
 * form, so it needed the exact same Brand-dropdown treatment — otherwise
 * every quick-created mobile product would fail brand validation the moment
 * Brand stopped being free text.
 */
class ProductQuickCreateBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_creating_a_mobile_product_with_a_selected_brand_succeeds(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $this->actingAs($owner);

        Livewire::test('products.quick-create', ['defaultMainCategorySlug' => 'mobile'])
            ->set('name', 'Galaxy A15')
            ->set('brand', $brand->name)
            ->set('price', '25000')
            ->set('stock_quantity', '3')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Galaxy A15')->firstOrFail();
        $this->assertSame('Samsung', $product->details['brand']);
        $this->assertNull($product->details['model']);
    }

    public function test_selecting_new_brand_in_the_quick_create_modal_opens_its_own_quick_create(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.quick-create', ['defaultMainCategorySlug' => 'mobile'])
            ->set('brand', '__create__')
            ->assertSet('brand', '')
            ->assertDispatched('open-modal', name: 'quick-create-brand');
    }

    public function test_creating_a_brand_inline_from_the_quick_create_modal_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $quickProduct = Livewire::test('products.quick-create', ['defaultMainCategorySlug' => 'mobile']);
        $quickBrand = Livewire::test('brands.quick-create');

        $quickBrand->set('name', 'Vivo')->call('save')->assertHasNoErrors();

        $quickProduct->call('onBrandCreated', 'Vivo');
        $quickProduct->assertSet('brand', 'Vivo');
    }
}
