<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSimLegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sim_is_no_longer_offered_as_a_creatable_product_type(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->assertViewHas('mainCategories', function ($categories) {
                $slugs = $categories->pluck('slug')->all();

                return in_array('mobile', $slugs, true)
                    && in_array('accessory', $slugs, true)
                    && ! in_array('sim', $slugs, true);
            });
    }

    public function test_a_tampered_type_of_sim_is_rejected_and_does_not_crash_the_save_flow(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        // 'sim' matches no Main Category slug (Main Categories never
        // include it), so the type-exists validation rejects it outright —
        // a stronger guarantee than before, when it merely fell through to
        // generic rules. The important part is nothing crashes either way.
        Livewire::test('products.index')
            ->set('type', 'sim')
            ->set('name', 'Tampered SIM')
            ->set('price', '500')
            ->set('stock_quantity', '1')
            ->call('save')
            ->assertHasErrors(['type']);

        $this->assertFalse(Product::where('name', 'Tampered SIM')->exists());
    }

    public function test_an_existing_legacy_sim_product_remains_visible_and_readable(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $simProduct = Product::create([
            'shop_id' => $shop->id,
            'type' => 'sim',
            'name' => 'Jazz Prepaid SIM',
            'price' => 100,
            'stock_quantity' => 5,
            'details' => ['sim_type' => 'prepaid', 'sim_form' => 'physical', 'network' => 'Jazz'],
        ]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->assertSee('Jazz Prepaid SIM')
            ->assertSee('Legacy');

        $this->assertNotNull($simProduct->fresh());
        $this->assertSame('sim', $simProduct->fresh()->type);
    }

    public function test_editing_a_legacy_sim_product_is_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $simProduct = Product::create([
            'shop_id' => $shop->id,
            'type' => 'sim',
            'name' => 'Jazz Prepaid SIM',
            'price' => 100,
            'stock_quantity' => 5,
            'details' => ['sim_type' => 'prepaid', 'sim_form' => 'physical', 'network' => 'Jazz'],
        ]);

        $this->actingAs($owner);

        // abort_if() inside a Livewire method doesn't propagate as a raised
        // exception through Livewire::test()->call() — it's absorbed
        // internally — so the guarantee to check is the effect: the edit
        // form never gets populated with the legacy product's data.
        Livewire::test('products.index')
            ->call('openEdit', $simProduct->id)
            ->assertSet('editingId', null)
            ->assertSet('name', '');
    }

    public function test_the_type_filter_still_includes_sim_so_legacy_products_stay_findable(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'sim', 'name' => 'Zong eSIM', 'price' => 100, 'stock_quantity' => 5, 'details' => []]);
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 100000, 'stock_quantity' => 2, 'details' => []]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('typeFilter', 'sim')
            ->assertSee('Zong eSIM')
            ->assertDontSee('iPhone 13');
    }

    public function test_deleting_a_legacy_sim_product_does_not_corrupt_a_historical_sale_referencing_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $simProduct = Product::create([
            'shop_id' => $shop->id,
            'type' => 'sim',
            'name' => 'Jazz Prepaid SIM',
            'price' => 100,
            'stock_quantity' => 5,
            'details' => ['sim_type' => 'prepaid', 'sim_form' => 'physical', 'network' => 'Jazz'],
        ]);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        $saleItem = SaleItem::create([
            'shop_id' => $shop->id,
            'sale_id' => $sale->id,
            'product_id' => $simProduct->id,
            'product_name' => 'Jazz Prepaid SIM',
            'product_type' => 'sim',
            'unit_price' => 100,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 100,
        ]);

        $this->actingAs($owner);

        Livewire::test('products.index')->call('delete', $simProduct->id);

        $this->assertNull(Product::find($simProduct->id));

        $saleItem->refresh();
        $this->assertNull($saleItem->product_id);
        $this->assertSame('Jazz Prepaid SIM', $saleItem->product_name);
        $this->assertSame('sim', $saleItem->product_type);
        $this->assertSame(100.0, (float) $saleItem->line_total);
    }

    public function test_historical_sim_sales_revenue_still_appears_in_the_sales_by_category_report(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create([
            'shop_id' => $shop->id,
            'sale_id' => $sale->id,
            'product_name' => 'Legacy SIM',
            'product_type' => 'sim',
            'unit_price' => 500,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 500,
        ]);

        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('day', $today)
            ->assertSee('SIM / eSIM')
            ->assertSee('Rs 500.00');
    }
}
