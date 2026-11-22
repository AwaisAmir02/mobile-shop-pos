<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\UpdateSale;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * C5: promotions v1. A % or flat amount off between two dates, on products,
 * variant groups, categories or everything, applied by the register by
 * itself and recorded on each sale line.
 */
class PromotionsTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Libas Store', 'business_category' => 'clothing']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->actingAs($this->owner);
        MainCategory::ensureDefaultsExist($this->shop->id);
        MainCategory::create(['shop_id' => $this->shop->id, 'name' => 'Shirts', 'slug' => 'shirts']);
        MainCategory::create(['shop_id' => $this->shop->id, 'name' => 'Shoes', 'slug' => 'shoes']);
    }

    protected function product(string $name, float $price, int $stock = 20, array $extra = []): Product
    {
        $product = Product::create($extra + [
            'shop_id' => $this->shop->id, 'type' => 'shirts', 'name' => $name, 'price' => $price, 'cost_price' => $price / 2, 'stock_quantity' => 0,
            'details' => ['category' => null],
        ]);

        if ($stock > 0) {
            CreateStockIn::handle([['product_id' => $product->id, 'quantity' => $stock, 'cost_price' => $price / 2, 'selling_price' => $price]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
        }

        return $product->fresh();
    }

    protected function promotion(array $attributes = []): Promotion
    {
        return Promotion::create($attributes + [
            'shop_id' => $this->shop->id, 'name' => 'Eid Sale', 'kind' => Promotion::PERCENT, 'value' => 20,
            'applies_to' => Promotion::ALL, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString(),
        ]);
    }

    /** Sells through the register: each [product, quantity], with an optional line discount. */
    protected function sell(array $lines): Sale
    {
        $register = Livewire::test('sales.create');

        foreach ($lines as [$product, $quantity, $discount]) {
            $register->call('addToCart', $product->id)->set("cart.{$product->id}.quantity", $quantity)->set("cart.{$product->id}.discount", (string) $discount);
        }

        $register->call('completeSale')->assertHasNoErrors();

        return Sale::query()->latest('id')->firstOrFail();
    }

    public function test_the_register_takes_a_running_promotion_off_by_itself_and_the_line_records_it(): void
    {
        $shirt = $this->product('Polo Shirt', 1000);
        $promotion = $this->promotion();

        Livewire::test('sales.create')
            ->call('addToCart', $shirt->id)
            ->set("cart.{$shirt->id}.quantity", 2)
            ->assertSee('Eid Sale · 20% off · Rs 400.00 off');

        $sale = $this->sell([[$shirt, 2, 0]]);

        $this->assertEquals(1600, (float) $sale->total);
        $item = $sale->items()->sole();
        $this->assertSame([$promotion->id, 'Eid Sale', '400.00', '400.00'], [$item->promotion_id, $item->promotion_name, $item->promotion_discount, $item->discount_amount]);

        Livewire::test('sales.show', ['sale' => $sale])->assertSee('Eid Sale: Rs 400.00 off');
        $this->assertStringContainsString('Eid Sale: Rs 400.00 off', view('pdf.invoice', ['sale' => $sale->fresh(['items'])])->render());
    }

    public function test_scopes_cover_only_their_products_groups_and_categories(): void
    {
        $group = ProductGroup::create(['shop_id' => $this->shop->id, 'name' => 'Kurta', 'type' => 'shirts']);
        $kurtaM = $this->product('Kurta M', 2000, extra: ['product_group_id' => $group->id]);
        $polo = $this->product('Polo', 1000);
        $sneaker = $this->product('Sneaker', 3000, extra: ['type' => 'shoes']);

        $this->promotion(['name' => 'Kurta week', 'applies_to' => Promotion::GROUPS, 'targets' => [(string) $group->id], 'value' => 10]);
        $this->promotion(['name' => 'Shoe fest', 'applies_to' => Promotion::CATEGORIES, 'targets' => ['shoes'], 'value' => 25]);
        $this->promotion(['name' => 'Polo deal', 'applies_to' => Promotion::PRODUCTS, 'targets' => [(string) $polo->id], 'kind' => Promotion::FLAT, 'value' => 150]);

        $sale = $this->sell([[$kurtaM, 1, 0], [$polo, 2, 0], [$sneaker, 1, 0]]);

        $byProduct = $sale->items->keyBy('product_id');
        $this->assertSame(['Kurta week', '200.00'], [$byProduct[$kurtaM->id]->promotion_name, $byProduct[$kurtaM->id]->promotion_discount]);
        $this->assertSame(['Polo deal', '300.00'], [$byProduct[$polo->id]->promotion_name, $byProduct[$polo->id]->promotion_discount], 'Rs 150 off each of two');
        $this->assertSame(['Shoe fest', '750.00'], [$byProduct[$sneaker->id]->promotion_name, $byProduct[$sneaker->id]->promotion_discount]);
        $this->assertEquals(2000 + 2000 + 3000 - 200 - 300 - 750, (float) $sale->total);
    }

    public function test_a_promotion_not_running_that_day_is_left_out(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $this->promotion(['name' => 'Later', 'starts_on' => now()->addDay()->toDateString()]);
        $this->promotion(['name' => 'Over', 'starts_on' => now()->subDays(10)->toDateString(), 'ends_on' => now()->subDay()->toDateString()]);
        $this->promotion(['name' => 'Paused', 'is_active' => false]);

        $item = $this->sell([[$shirt, 1, 0]])->items()->sole();

        $this->assertNull($item->promotion_id);
        $this->assertSame('0.00', $item->discount_amount);
    }

    public function test_a_backdated_sale_gets_the_promotions_of_its_own_day(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $this->promotion(['name' => 'Last week', 'starts_on' => now()->subDays(8)->toDateString(), 'ends_on' => now()->subDays(6)->toDateString()]);

        Livewire::test('sales.create')
            ->set('saleDate', now()->subDays(7)->toDateString())
            ->call('addToCart', $shirt->id)
            ->call('completeSale')->assertHasNoErrors();

        $this->assertSame('Last week', Sale::sole()->items()->sole()->promotion_name);
    }

    public function test_the_line_gets_the_promotion_that_takes_off_most_and_a_flat_one_never_goes_below_zero(): void
    {
        $cheap = $this->product('Socks', 100);
        $dear = $this->product('Jacket', 5000);
        $this->promotion(['name' => '10 percent', 'value' => 10]);
        $this->promotion(['name' => 'Rs 300 off', 'kind' => Promotion::FLAT, 'value' => 300]);

        $sale = $this->sell([[$cheap, 2, 0], [$dear, 1, 0]]);
        $byProduct = $sale->items->keyBy('product_id');

        $this->assertSame(['Rs 300 off', '200.00', '0.00'], [$byProduct[$cheap->id]->promotion_name, $byProduct[$cheap->id]->promotion_discount, $byProduct[$cheap->id]->line_total], 'flat beats 10% on socks, capped at the line');
        $this->assertSame(['10 percent', '500.00'], [$byProduct[$dear->id]->promotion_name, $byProduct[$dear->id]->promotion_discount]);
    }

    public function test_the_cashier_discount_comes_off_first_and_both_add_up_on_the_line(): void
    {
        $shirt = $this->product('Shirt', 100);
        $this->promotion(['value' => 10]);

        $item = $this->sell([[$shirt, 2, 20]])->items()->sole();

        // 200 - 20 = 180; 10% of that is 18.
        $this->assertSame(['38.00', '18.00', '162.00'], [$item->discount_amount, $item->promotion_discount, $item->line_total]);
    }

    public function test_a_line_split_across_two_batches_splits_the_promotion_exactly(): void
    {
        $shirt = $this->product('Shirt', 999, stock: 0);
        CreateStockIn::handle([['product_id' => $shirt->id, 'quantity' => 1, 'cost_price' => 500, 'selling_price' => 999]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
        CreateStockIn::handle([['product_id' => $shirt->id, 'quantity' => 2, 'cost_price' => 500, 'selling_price' => 999]], null, 'paid', null, '2026-10-02', null, $this->owner->id);
        $this->promotion(['value' => 15]);

        $items = $this->sell([[$shirt, 3, 10]])->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        // (2997 - 10) x 15% = 448.05
        $this->assertEquals(448.05, round($items->sum(fn ($item) => (float) $item->promotion_discount), 2));
        $this->assertEquals(458.05, round($items->sum(fn ($item) => (float) $item->discount_amount), 2));
        $this->assertEquals(2997 - 458.05, round($items->sum(fn ($item) => (float) $item->line_total), 2));
    }

    public function test_a_pack_line_gets_the_promotion_on_the_pack_price(): void
    {
        $tablets = $this->product('Panadol', 3, 100, ['pack_name' => 'Strip', 'pack_size' => 10, 'pack_price' => 28]);
        $this->promotion(['value' => 50]);

        Livewire::test('sales.create')
            ->call('addToCart', $tablets->id)
            ->call('sellAs', $tablets->id, 'pack')
            ->set("cart.{$tablets->id}.quantity", 2)
            ->call('completeSale')->assertHasNoErrors();

        $this->assertEquals(28, (float) Sale::sole()->total);
        $this->assertSame('2 strips', Sale::sole()->items()->sole()->packLabel());
    }

    public function test_sales_not_made_at_the_register_get_no_promotion(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $this->promotion();

        // WhatsApp and online orders go through CreateSale without asking for promotions.
        $sale = CreateSale::handle([['product_id' => $shirt->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->owner->id, now()->toDateString(), 1000);

        $this->assertNull($sale->items()->sole()->promotion_id);
        $this->assertEquals(1000, (float) $sale->total);
    }

    public function test_an_edit_keeps_the_promotion_on_the_new_quantity_and_adds_none(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $belt = $this->product('Belt', 500);
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Hina']);
        $promotion = $this->promotion(['applies_to' => Promotion::PRODUCTS, 'targets' => [(string) $shirt->id]]);

        $sale = $this->sell([[$shirt, 2, 50]]);
        $sale->update(['customer_id' => $customer->id]);

        // The edit screen shows the cashier's own discount; the promotion is shown, not typed.
        Livewire::test('sales.edit', ['sale' => $sale->fresh()])
            ->assertSet("cart.{$shirt->id}.discount", '50')
            ->assertSee('Eid Sale · 20% off · Rs 390.00 off');

        // Ended since, and the belt now covered too: the shirt keeps it, the belt gets none.
        $promotion->update(['ends_on' => now()->subDays(1)->toDateString(), 'starts_on' => now()->subDays(2)->toDateString()]);
        $this->promotion(['name' => 'Belts', 'applies_to' => Promotion::PRODUCTS, 'targets' => [(string) $belt->id]]);

        $line = fn ($product, $quantity, $discount) => ['product_id' => $product->id, 'product_name' => $product->name, 'product_type' => 'shirts', 'quantity' => $quantity, 'discount' => $discount, 'custom_price' => null];
        UpdateSale::handle($sale->fresh(), [$line($shirt, 3, 50.0), $line($belt, 1, 0.0)], 0.0, $customer->id, $this->owner->id, $sale->created_at->toDateString());

        $byProduct = $sale->fresh()->items->keyBy('product_id');
        $this->assertSame(['Eid Sale', '590.00', '640.00'], [$byProduct[$shirt->id]->promotion_name, $byProduct[$shirt->id]->promotion_discount, $byProduct[$shirt->id]->discount_amount]);
        $this->assertNull($byProduct[$belt->id]->promotion_id);
    }

    public function test_the_screen_adds_a_promotion_and_checks_what_it_needs(): void
    {
        $polo = $this->product('Polo Shirt', 1000);

        Livewire::test('promotions.index')
            ->call('openCreate')
            ->set('name', 'Summer')->set('kind', 'percent')->set('value', '120')
            ->set('appliesTo', 'products')
            ->call('save')
            ->assertHasErrors(['value', 'targets']);

        Livewire::test('promotions.index')
            ->call('openCreate')
            ->set('name', 'Summer')->set('value', '15')
            ->set('appliesTo', 'products')
            ->set('targetSearch', 'Polo')
            ->assertSee('Polo Shirt')
            ->call('addTarget', $polo->id)
            ->set('endsOn', now()->addDays(3)->toDateString())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Summer')
            ->assertSee('Running');

        $promotion = Promotion::sole();
        $this->assertSame([Promotion::PRODUCTS, [(string) $polo->id], '15.00'], [$promotion->applies_to, $promotion->targets, $promotion->value]);
    }

    public function test_a_used_promotion_keeps_its_discount_and_cannot_be_deleted_but_can_be_paused(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $promotion = $this->promotion();
        $this->sell([[$shirt, 1, 0]]);

        Livewire::test('promotions.index')
            ->assertSee('Rs 200.00 on 1 sale')
            ->call('openEdit', $promotion->id)
            ->assertSee('only its name and dates can change')
            ->set('value', '30')
            ->call('save')
            ->assertHasErrors('value');

        Livewire::test('promotions.index')
            ->call('openEdit', $promotion->id)
            ->set('endsOn', now()->addDays(30)->toDateString())->set('name', 'Eid Sale extended')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Eid Sale extended', $promotion->fresh()->name);

        Livewire::test('promotions.index')->call('delete', $promotion->id);
        $this->assertModelExists($promotion);

        Livewire::test('promotions.index')->call('togglePause', $promotion->id)->assertSee('Paused');
        $this->assertNull($this->sell([[$shirt, 1, 0]])->items()->sole()->promotion_id);
    }

    public function test_an_unused_promotion_can_be_deleted(): void
    {
        $promotion = $this->promotion();

        Livewire::test('promotions.index')->call('delete', $promotion->id);

        $this->assertModelMissing($promotion);
    }

    public function test_staff_need_the_promotions_permission_and_see_it_in_the_sidebar(): void
    {
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('promotions.index'))->assertForbidden();

        $role->update(['permissions' => ['sales', 'promotions']]);
        $this->actingAs($staff->fresh());
        $this->get(route('promotions.index'))->assertOk();
        $this->get(route('sales.index'))->assertSee(route('promotions.index'));
    }

    public function test_promotions_belong_to_their_own_shop(): void
    {
        $this->promotion(['name' => 'Ours']);
        $other = Shop::create(['name' => 'Other', 'business_category' => 'clothing']);
        Promotion::create(['shop_id' => $other->id, 'name' => 'Theirs', 'kind' => Promotion::PERCENT, 'value' => 50, 'applies_to' => Promotion::ALL, 'starts_on' => now()->subDay()->toDateString()]);
        $shirt = $this->product('Shirt', 1000);

        Livewire::test('promotions.index')->assertSee('Ours')->assertDontSee('Theirs');
        $this->assertSame('Ours', $this->sell([[$shirt, 1, 0]])->items()->sole()->promotion_name);
    }
}
