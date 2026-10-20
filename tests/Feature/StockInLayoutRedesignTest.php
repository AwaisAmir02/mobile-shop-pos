<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInLayoutRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_vendor_and_payment_section_is_visible_before_any_product_is_added(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        $this->actingAs($owner);

        $html = $this->get(route('stock-ins.index'))->getContent();

        $this->assertStringContainsString('Vendor &amp; Payment', $html);
        $this->assertStringContainsString('Acme Distributors', $html);
        $this->assertStringContainsString('wire:model="vendorId"', $html);
        $this->assertStringContainsString('Add to Receipt', $html);
    }

    public function test_the_add_button_relabels_to_add_more_once_a_product_has_been_added(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '5')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->assertSee('Add More')
            ->assertDontSee('Add to Receipt');
    }

    public function test_each_added_product_renders_as_its_own_numbered_removable_block(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $phone = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'stock_quantity' => 0, 'details' => []]);
        $cable = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        $component = Livewire::test('stock-ins.create')
            ->set('productId', (string) $phone->id)
            ->set('quantity', '1')
            ->set('sellingPrice', '145000')
            ->call('addLine')
            ->set('productId', (string) $cable->id)
            ->set('quantity', '10')
            ->set('sellingPrice', '500')
            ->call('addLine');

        $html = $component->html();

        $this->assertStringContainsString('iPhone 13', $html);
        $this->assertStringContainsString('USB Cable', $html);
        $this->assertSame(2, substr_count($html, 'removeLine('));

        $component->call('removeLine', $phone->id);

        $this->assertStringNotContainsString("removeLine({$phone->id})", $component->html());
        $this->assertStringContainsString("removeLine({$cable->id})", $component->html());
        $this->assertArrayNotHasKey($phone->id, $component->get('cart'));
    }

    public function test_the_full_vendor_plus_multi_product_save_flow_still_works_end_to_end_in_the_new_layout(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        $phone = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'stock_quantity' => 0, 'details' => []]);
        $cable = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('vendorId', (string) $vendor->id)
            ->set('productId', (string) $phone->id)
            ->set('quantity', '2')
            ->set('costPrice', '130000')
            ->set('sellingPrice', '145000')
            ->call('addLine')
            ->set('productId', (string) $cable->id)
            ->set('quantity', '10')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, StockIn::where('shop_id', $shop->id)->where('vendor_id', $vendor->id)->count());
    }

    public function test_the_save_button_and_total_cost_are_visible_even_before_any_product_is_added(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('stock-ins.index'))
            ->assertSee('wire:click="save"', false)
            ->assertSee('Total Cost');
    }
}
