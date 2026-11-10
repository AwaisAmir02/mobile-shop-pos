<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Add Product wizard (Details, then how it's sold, saved
 * together; owner decision V2, no renaming) and the product detail page.
 */
class ProductWizardAndDetailTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $flags = ['variants_enabled' => true]): User
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $shop->forceFill($flags)->save();
        MainCategory::ensureDefaultsExist($shop->id);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);
        AccessoryCategoryOption::ensureDefaultsExist();

        return $owner;
    }

    private function category(): string
    {
        return AccessoryCategoryOption::query()
            ->whereHas('mainCategory', fn ($query) => $query->where('slug', 'accessory'))
            ->value('name');
    }

    private function details(string $name = 'Cotton Kurta')
    {
        return Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', $name)
            ->set('category', $this->category())
            ->set('price', '2400')
            ->set('cost_price', '1500');
    }

    public function test_details_come_first_and_are_checked_before_moving_on(): void
    {
        $this->owner();

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->assertViewHas('wizard', true)
            ->call('nextStep')
            ->assertHasErrors(['name', 'price'])
            ->assertSet('formStep', 1);

        $this->details()->call('nextStep')->assertHasNoErrors()->assertSet('formStep', 2);
    }

    public function test_one_product_is_saved_by_the_existing_save_with_its_stock(): void
    {
        $this->owner();

        $this->details('Leather Wallet')
            ->call('nextStep')
            ->set('stock_quantity', '7')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::sole();
        $this->assertSame('Leather Wallet', $product->name);
        $this->assertEquals(7, $product->stock_quantity);
        $this->assertNull($product->product_group_id);
    }

    public function test_sizes_and_colours_create_the_group_and_its_variants_in_one_go_without_renaming(): void
    {
        $this->owner();

        $this->details()
            ->call('nextStep')
            ->set('withVariants', true)
            ->set('groupSizes', 'S, M')
            ->set('groupColours', 'White')
            ->assertViewHas('wizardLabels', ['S / White', 'M / White'])
            ->call('saveWithVariants')
            ->assertHasNoErrors()
            ->assertSet('formStep', 1)
            ->assertSet('name', '')
            ->assertDispatched('close-modal', name: 'product-form');

        $group = ProductGroup::sole();
        $this->assertSame('Cotton Kurta', $group->name);
        $this->assertSame('accessory', $group->type);
        $this->assertSame($this->category(), $group->details['category']);

        $variants = Product::orderBy('variant_label')->get();
        $this->assertSame(['M / White', 'S / White'], $variants->pluck('variant_label')->all());
        $this->assertSame(['Cotton Kurta - M / White', 'Cotton Kurta - S / White'], $variants->pluck('name')->all());
        $this->assertTrue($variants->every(fn (Product $variant) => (float) $variant->price === 2400.0 && (float) $variant->cost_price === 1500.0 && (float) $variant->stock_quantity === 0.0));
        $this->assertSame(0, Product::where('name', 'Cotton Kurta')->count(), 'No stand-alone product is created and then renamed.');
    }

    public function test_a_problem_with_the_details_is_shown_on_the_details_step(): void
    {
        $this->owner();
        $this->details()->call('nextStep')->set('withVariants', true)->set('groupSizes', 'S')->call('saveWithVariants');

        $this->details()
            ->call('nextStep')
            ->set('withVariants', true)
            ->set('groupSizes', 'L')
            ->call('saveWithVariants')
            ->assertHasErrors('name')
            ->assertSet('formStep', 1);

        $this->assertSame(1, ProductGroup::count());
    }

    public function test_variants_need_at_least_one_size_or_colour(): void
    {
        $this->owner();

        $this->details()->call('nextStep')->set('withVariants', true)->call('saveWithVariants')->assertHasErrors('groupSizes');

        $this->assertSame(0, ProductGroup::count());
        $this->assertSame(0, Product::count());
    }

    public function test_the_wizard_is_only_for_new_products_that_can_have_variants(): void
    {
        $this->owner();

        // Mobiles can't have variants, so they keep the single form.
        Livewire::test('products.index')->call('openCreate')->set('type', 'mobile')
            ->assertViewHas('wizard', false)
            ->call('saveWithVariants')->assertForbidden();

        $product = Product::create(['shop_id' => auth()->user()->shop_id, 'type' => 'accessory', 'name' => 'Old', 'price' => 10, 'stock_quantity' => 0, 'details' => []]);
        Livewire::test('products.index')->call('openEdit', $product->id)->assertViewHas('wizard', false);
    }

    public function test_with_variants_off_the_form_is_a_single_step(): void
    {
        $this->owner(['variants_enabled' => false]);

        Livewire::test('products.index')->call('openCreate')->set('type', 'accessory')
            ->assertViewHas('wizard', false)
            ->call('nextStep')->assertForbidden();
    }

    public function test_the_detail_page_shows_one_product_of_this_shop_only(): void
    {
        $owner = $this->owner();
        $product = Product::create(['shop_id' => $owner->shop_id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1800, 'stock_quantity' => 4, 'details' => []]);

        $this->get(route('products.show', $product))->assertOk()->assertSee('Charger')->assertSee('Rs 1,800.00');

        $other = Shop::create(['name' => 'Shop B']);
        $theirs = Product::withoutGlobalScopes()->create(['shop_id' => $other->id, 'type' => 'accessory', 'name' => 'Not yours', 'price' => 1, 'stock_quantity' => 1, 'details' => []]);
        $this->get(route('products.show', $theirs->id))->assertNotFound();
    }

    public function test_detail_sections_follow_the_users_own_permissions(): void
    {
        $owner = $this->owner();
        $product = Product::create(['shop_id' => $owner->shop_id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1800, 'stock_quantity' => 4, 'details' => []]);

        $this->get(route('products.show', $product))
            ->assertSee('data-product-tab="batches"', false)
            ->assertSee('data-product-tab="sales"', false)
            ->assertSee('Cost price');

        $role = Role::create(['shop_id' => $owner->shop_id, 'name' => 'Stock viewer', 'permissions' => ['products']]);
        $staff = User::factory()->staff()->create(['shop_id' => $owner->shop_id, 'role_id' => $role->id]);

        $this->actingAs($staff)->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('data-product-tab', false)
            ->assertDontSee('Cost price');

        Livewire::actingAs($staff)->test('products.show', ['product' => $product])->call('setTab', 'batches')->assertSet('tab', 'overview');

        $cashier = User::factory()->staff()->create(['shop_id' => $owner->shop_id, 'role_id' => Role::create(['shop_id' => $owner->shop_id, 'name' => 'Cashier', 'permissions' => ['sales']])->id]);
        $this->actingAs($cashier)->get(route('products.show', $product))->assertForbidden();
    }

    public function test_the_list_links_to_the_detail_page_and_edit_comes_back_to_the_form(): void
    {
        $owner = $this->owner();
        $product = Product::create(['shop_id' => $owner->shop_id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1800, 'stock_quantity' => 4, 'details' => []]);

        $this->get(route('products.index'))->assertSee(route('products.show', $product), false);
        $this->get(route('products.show', $product))->assertSee(route('products.index', ['edit' => $product->id]), false);

        Livewire::withQueryParams(['edit' => $product->id])->test('products.index')->assertSet('editingId', $product->id);
    }
}
