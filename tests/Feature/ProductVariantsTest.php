<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Models\MainCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function shop(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Shop A', 'variants_enabled' => true, 'barcode_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Clothing', 'slug' => 'clothing']);

        return $shop;
    }

    protected function group(Shop $shop, string $name = 'Cotton Shirt', array $labels = ['S / Red', 'M / Red'], float $price = 1000): ProductGroup
    {
        $service = app(ProductGroupService::class);
        $group = $service->createGroup($shop->id, $name, 'clothing', '');
        $service->addVariants($group, $labels, $price, 600);

        return $group->fresh();
    }

    protected function standalone(Shop $shop, string $name = 'Plain Shirt', array $attributes = []): Product
    {
        return Product::create($attributes + ['shop_id' => $shop->id, 'type' => 'clothing', 'name' => $name, 'price' => 500, 'stock_quantity' => 0, 'details' => ['category' => null]]);
    }

    protected function batch(Product $product, int $quantity, float $cost, float $selling, string $date = '2026-11-01'): StockIn
    {
        $product->increment('stock_quantity', $quantity);

        return StockIn::create([
            'shop_id' => $product->shop_id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'quantity_remaining' => $quantity, 'cost_price' => $cost, 'selling_price' => $selling, 'stock_date' => $date,
        ]);
    }

    // ── Feature switch ─────────────────────────────────────────────────

    public function test_the_feature_is_off_by_default_and_blocks_managing_groups(): void
    {
        $shop = $this->shop(['variants_enabled' => false]);
        $this->assertFalse(Shop::create(['name' => 'New'])->fresh()->variants_enabled);

        Livewire::test('products.index')->assertDontSee('Add Variant Group')->call('openCreateGroup')->assertForbidden();
    }

    public function test_the_plan_never_controls_the_feature(): void
    {
        $this->assertNotContains(FeatureKey::Variants, FeatureKey::planCases());

        $plan = Plan::create(['name' => 'Test Plan', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => [], 'feature_flags' => ['barcode' => true]]);
        $shop = Shop::create(['name' => 'Governed', 'plan_id' => $plan->id, 'variants_enabled' => true]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        $editor = Livewire::test('admin.plans')->call('openEdit', $plan->id);
        $this->assertArrayNotHasKey('variants', $editor->get('featureFlags'));
        $editor->assertDontSee('Product variants')->call('save');

        $this->assertArrayNotHasKey('variants', $plan->fresh()->feature_flags);
        $this->assertTrue($shop->fresh()->variants_enabled);

        app(Entitlements::class)->syncFeatureFlags($shop->fresh());
        $this->assertTrue($shop->fresh()->variants_enabled);
    }

    public function test_the_admin_switch_sets_the_column_without_a_plan_override(): void
    {
        $plan = Plan::create(['name' => 'Test Plan', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => [], 'feature_flags' => []]);
        $shop = Shop::create(['name' => 'Governed', 'plan_id' => $plan->id]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleFeature', 'variants');

        $this->assertTrue($shop->fresh()->variants_enabled);
        $this->assertNull($shop->fresh()->feature_overrides);
    }

    // ── Plan limit ─────────────────────────────────────────────────────

    public function test_a_group_counts_as_one_product_and_archiving_all_its_variants_frees_it(): void
    {
        $shop = $this->shop();
        $this->standalone($shop, 'One');
        $this->standalone($shop, 'Two');
        $this->standalone($shop, 'Archived', ['archived_at' => now()]);
        $group = $this->group($shop, labels: ['S', 'M', 'L']);

        $entitlements = app(Entitlements::class);
        $this->assertSame(3, $entitlements->usage($shop, LimitKey::Products));

        $group->variants()->first()->update(['archived_at' => now()]);
        $this->assertSame(3, $entitlements->usage($shop, LimitKey::Products));

        $group->variants()->update(['archived_at' => now()]);
        $this->assertSame(2, $entitlements->usage($shop, LimitKey::Products));
    }

    public function test_a_new_group_needs_room_on_the_plan_but_more_variants_in_an_existing_one_do_not(): void
    {
        $plan = Plan::create(['name' => 'Tiny', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['products' => 1], 'feature_flags' => []]);
        $shop = $this->shop(['plan_id' => $plan->id]);
        $group = $this->group($shop, labels: ['S']);

        Livewire::test('products.index')
            ->call('openCreateGroup')
            ->set('groupName', 'Second Group')->set('groupType', 'clothing')->set('groupSizes', 'S, M')->set('groupPrice', '100')
            ->call('saveGroup');
        $this->assertSame(1, ProductGroup::count());

        Livewire::test('products.index')
            ->call('openAddVariants', $group->id)
            ->set('groupSizes', 'M, L')->set('groupPrice', '100')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $this->assertSame(3, $group->variants()->count());
        $this->assertSame(1, app(Entitlements::class)->usage($shop, LimitKey::Products));
    }

    public function test_ungrouping_is_refused_when_the_variants_would_not_fit_on_the_plan(): void
    {
        $plan = Plan::create(['name' => 'Tiny', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['products' => 2], 'feature_flags' => []]);
        $shop = $this->shop(['plan_id' => $plan->id]);
        $group = $this->group($shop, labels: ['S', 'M', 'L']);

        Livewire::test('products.index')->call('ungroup', $group->id);

        $this->assertNotNull($group->fresh());
        $this->assertSame(3, $group->variants()->count());
    }

    public function test_restoring_a_variant_with_a_counted_sibling_needs_no_room_on_the_plan(): void
    {
        $plan = Plan::create(['name' => 'Tiny', 'price' => 100, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['products' => 1], 'feature_flags' => []]);
        $shop = $this->shop(['plan_id' => $plan->id]);
        $group = $this->group($shop, labels: ['S', 'M']);
        $variant = $group->variants()->first();
        $variant->update(['archived_at' => now()]);

        Livewire::test('products.index')->call('restore', $variant->id);

        $this->assertNull($variant->fresh()->archived_at);
    }

    // ── Products screen ────────────────────────────────────────────────

    public function test_generating_a_size_by_colour_group_creates_each_variant_with_no_stock(): void
    {
        $shop = $this->shop();

        Livewire::test('products.index')
            ->call('openCreateGroup')
            ->set('groupName', 'Cotton Shirt')->set('groupType', 'clothing')
            ->set('groupSizes', 'S, M, m, ')->set('groupColours', 'Red, Blue')
            ->set('groupPrice', '1500')->set('groupCost', '900')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $group = ProductGroup::sole();
        $this->assertSame(['S / Red', 'S / Blue', 'M / Red', 'M / Blue'], $group->variants()->pluck('variant_label')->all());

        $variant = $group->variants()->first();
        $this->assertSame('Cotton Shirt - S / Red', $variant->name);
        $this->assertSame('clothing', $variant->type);
        $this->assertSame(0, $variant->stock_quantity);
        $this->assertSame('1500.00', $variant->price);
        $this->assertSame('900.00', $variant->cost_price);
        $this->assertSame($shop->id, $variant->shop_id);
    }

    public function test_a_group_cannot_be_a_mobile_or_have_a_duplicate_name_or_no_variants(): void
    {
        $shop = $this->shop();
        $this->group($shop);

        Livewire::test('products.index')->call('openCreateGroup')
            ->set('groupName', 'Phones')->set('groupType', 'mobile')->set('groupSizes', '64GB')->set('groupPrice', '100')
            ->call('saveGroup')->assertHasErrors('groupType');

        Livewire::test('products.index')->call('openCreateGroup')
            ->set('groupName', 'cotton shirt')->set('groupType', 'clothing')->set('groupSizes', 'S')->set('groupPrice', '100')
            ->call('saveGroup')->assertHasErrors('groupName');

        Livewire::test('products.index')->call('openCreateGroup')
            ->set('groupName', 'Empty')->set('groupType', 'clothing')
            ->call('saveGroup')->assertHasErrors('groupSizes');

        $this->assertSame(1, ProductGroup::count());
    }

    public function test_attaching_keeps_stock_batches_barcode_and_past_sale_names(): void
    {
        $shop = $this->shop();
        $product = $this->standalone($shop, 'Blue Shirt Medium', ['barcode' => '2000000000015']);
        $batch = $this->batch($product, 5, 300, 700);

        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, null, null, now()->toDateString(), 700);

        Livewire::test('products.index')
            ->call('openCreateGroup')
            ->set('groupName', 'Shirt')->set('groupType', 'clothing')
            ->call('addAttach', $product->id)
            ->set("attachLabels.{$product->id}", 'M / Blue')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame('Shirt - M / Blue', $product->name);
        $this->assertSame('M / Blue', $product->variant_label);
        $this->assertSame(4, $product->stock_quantity);
        $this->assertSame('2000000000015', $product->barcode);
        $this->assertSame(4, $batch->fresh()->quantity_remaining);
        $this->assertSame($product->id, $batch->fresh()->product_id);
        $this->assertSame('Blue Shirt Medium', $sale->items()->first()->product_name);
        $this->assertSame('Blue Shirt Medium', $batch->fresh()->product_name);
    }

    public function test_imei_tracked_products_cannot_be_attached_or_offered(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $phone = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy', 'price' => 100, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'imei' => '351756051523999']]);

        Livewire::test('products.index')->call('openAddVariants', $group->id)->set('attachSearch', 'Galaxy')->assertViewHas('attachCandidates', fn ($candidates) => $candidates->isEmpty());

        Livewire::test('products.index')
            ->call('openAddVariants', $group->id)
            ->call('addAttach', $phone->id)
            ->set("attachLabels.{$phone->id}", 'Black')
            ->call('saveGroup')
            ->assertHasErrors('attachLabels');

        $this->assertNull($phone->fresh()->product_group_id);
    }

    public function test_editing_a_variant_changes_its_label_and_name_but_not_its_category(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        [$small, $medium] = $group->variants()->get()->all();

        Livewire::test('products.index')
            ->call('openEdit', $small->id)
            ->assertSet('variantLabel', 'S / Red')
            ->set('variantLabel', 'M / Red')
            ->call('save')
            ->assertHasErrors('variantLabel');

        Livewire::test('products.index')
            ->call('openEdit', $small->id)
            ->set('variantLabel', 'XS / Red')
            ->set('type', 'accessory')
            ->set('price', '1250')
            ->call('save')
            ->assertHasNoErrors();

        $small->refresh();
        $this->assertSame('Cotton Shirt - XS / Red', $small->name);
        $this->assertSame('clothing', $small->type);
        $this->assertSame('1250.00', $small->price);
        $this->assertSame('1000.00', $medium->fresh()->price);
    }

    public function test_renaming_a_group_renames_its_variants_but_not_past_sales(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $variant = $group->variants()->first();
        $this->batch($variant, 3, 500, 1000);
        $sale = CreateSale::handle([['product_id' => $variant->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, null, null, now()->toDateString(), 1000);

        Livewire::test('products.index')
            ->call('openEditGroup', $group->id)
            ->set('groupName', 'Linen Shirt')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $this->assertSame('Linen Shirt - S / Red', $variant->fresh()->name);
        $this->assertSame('Cotton Shirt - S / Red', $sale->items()->first()->product_name);
    }

    public function test_deleting_the_last_variant_removes_the_group(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        [$first, $second] = $group->variants()->get()->all();

        Livewire::test('products.index')->call('delete', $first->id);
        $this->assertNotNull($group->fresh());

        Livewire::test('products.index')->call('delete', $second->id);
        $this->assertNull($group->fresh());
    }

    public function test_ungrouping_leaves_each_variant_a_normal_product(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $variant = $group->variants()->first();
        $this->batch($variant, 3, 500, 1000);

        Livewire::test('products.index')->call('ungroup', $group->id);

        $this->assertNull($group->fresh());
        $variant->refresh();
        $this->assertNull($variant->product_group_id);
        $this->assertNull($variant->variant_label);
        $this->assertSame('Cotton Shirt - S / Red', $variant->name);
        $this->assertSame(3, $variant->stock_quantity);
        $this->assertSame(2, app(Entitlements::class)->usage($shop, LimitKey::Products));
    }

    public function test_removing_one_variant_keeps_the_rest_grouped(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        [$first, $second] = $group->variants()->get()->all();

        Livewire::test('products.index')->call('removeFromGroup', $first->id);

        $this->assertNull($first->fresh()->product_group_id);
        $this->assertSame($group->id, $second->fresh()->product_group_id);
    }

    public function test_the_list_shows_a_group_once_and_a_search_opens_it(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop, labels: ['Small / Crimson', 'Medium / Crimson']);
        $this->standalone($shop, 'Plain Belt');

        Livewire::test('products.index')
            ->assertSee('Cotton Shirt')
            ->assertSee('2 variants')
            ->assertSee('Plain Belt')
            ->assertDontSee('Small / Crimson')
            ->call('toggleGroup', $group->id)
            ->assertSee('Small / Crimson');

        Livewire::test('products.index')->set('search', 'Medium / Crimson')->assertSee('Small / Crimson')->assertDontSee('Plain Belt');
    }

    public function test_the_product_report_still_lists_every_variant_by_full_name(): void
    {
        $shop = $this->shop();
        $this->group($shop);

        $rows = (new \App\ReportQueries\ProductReport)->tableRows(forExcel: true);

        $this->assertEqualsCanonicalizing(['Cotton Shirt - S / Red', 'Cotton Shirt - M / Red'], array_column($rows, 0));
    }

    public function test_a_shop_cannot_touch_another_shops_group(): void
    {
        $other = Shop::create(['name' => 'Other']);
        $service = app(ProductGroupService::class);
        $foreign = $service->createGroup($other->id, 'Foreign', 'clothing', '');
        $service->addVariants($foreign, ['S'], 100, null);

        $this->shop();

        foreach ([['products.index', 'openAddVariants'], ['products.index', 'openEditGroup'], ['products.index', 'ungroup'], ['sales.create', 'openVariantPicker']] as [$screen, $action]) {
            try {
                Livewire::test($screen)->call($action, $foreign->id);
                $this->fail("{$action} reached another shop's group.");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull($foreign->fresh());
    }

    // ── FIFO: each variant has its own batch trail ─────────────────────

    public function test_selling_a_variant_consumes_only_its_own_batches_and_cancelling_restores_them(): void
    {
        $shop = $this->shop();
        [$small, $medium] = $this->group($shop)->variants()->get()->all();
        $smallOld = $this->batch($small, 2, 100, 200, '2026-10-01');
        $smallNew = $this->batch($small, 5, 150, 300, '2026-10-05');
        $mediumOldest = $this->batch($medium, 4, 50, 90, '2026-09-01');

        $sale = CreateSale::handle([['product_id' => $small->id, 'quantity' => 3, 'discount' => 0, 'custom_price' => null]], 0, null, null, now()->toDateString(), 700);

        $this->assertSame(0, $smallOld->fresh()->quantity_remaining);
        $this->assertSame(4, $smallNew->fresh()->quantity_remaining);
        $this->assertSame(4, $mediumOldest->fresh()->quantity_remaining);
        $this->assertSame(4, $small->fresh()->stock_quantity);
        $this->assertSame(4, $medium->fresh()->stock_quantity);
        $this->assertSame(['100.00', '150.00'], $sale->items()->orderBy('id')->pluck('cost_price')->all());
        $this->assertSame('700.00', $sale->total);

        CancelSale::handle($sale, null);

        $this->assertSame(2, $smallOld->fresh()->quantity_remaining);
        $this->assertSame(5, $smallNew->fresh()->quantity_remaining);
        $this->assertSame(7, $small->fresh()->stock_quantity);
    }

    // ── POS ────────────────────────────────────────────────────────────

    public function test_pos_search_collapses_a_group_and_the_picker_adds_the_chosen_variant(): void
    {
        $shop = $this->shop();
        $labels = array_map(fn (int $i) => "Size {$i}", range(1, 12));
        $group = $this->group($shop, labels: $labels);
        $group->variants()->update(['stock_quantity' => 2]);
        $soldOut = $group->variants()->first();
        $soldOut->update(['stock_quantity' => 0]);
        $chosen = $group->variants()->where('variant_label', 'Size 5')->sole();
        $this->standalone($shop, 'Cotton Socks', ['stock_quantity' => 3]);

        $component = Livewire::test('sales.create')
            ->set('search', 'Cotton')
            ->assertSee('11 variants')
            ->assertSee('From Rs 1,000.00')
            ->assertSee('Cotton Socks')
            ->assertDontSee('Size 5')
            ->call('openVariantPicker', $group->id)
            ->assertSee('Size 5')
            ->assertSee('Size 1')
            ->assertSee('Out of stock');

        $component->call('addToCart', $chosen->id)
            ->assertSet("cart.{$chosen->id}.name", 'Cotton Shirt - Size 5')
            ->assertSet('search', '')
            ->assertSet('pickerGroupId', null);
    }

    public function test_pos_search_matching_one_variant_lists_it_directly(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $group->variants()->update(['stock_quantity' => 2]);

        Livewire::test('sales.create')->set('search', 'S / Red')->assertSee('Cotton Shirt - S / Red')->assertDontSee('choose a variant')->assertDontSee('variants ·');
    }

    public function test_scanning_a_variant_barcode_adds_that_exact_variant(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $variant = $group->variants()->first();
        $variant->update(['stock_quantity' => 2, 'barcode' => '2000000000015']);

        Livewire::test('sales.create')->call('scanBarcode', '2000000000015')->assertSet("cart.{$variant->id}.quantity", 1);
    }

    public function test_a_shop_with_no_groups_sees_the_same_search_results(): void
    {
        $shop = $this->shop(['variants_enabled' => false]);
        foreach (range(1, 10) as $i) {
            $this->standalone($shop, "Cable {$i}", ['stock_quantity' => 1]);
        }

        $results = Livewire::test('sales.create')->set('search', 'Cable')->viewData('results');

        $this->assertCount(8, $results);
        $this->assertSame(
            Product::where('name', 'like', '%Cable%')->orderBy('name')->limit(8)->pluck('id')->all(),
            $results->pluck('id')->all(),
        );
    }

    public function test_selling_prices_for_many_products_match_each_products_current_price(): void
    {
        $shop = $this->shop();
        [$withBatch, $withoutBatch] = $this->group($shop)->variants()->get()->all();
        $this->batch($withBatch, 2, 100, 777, '2026-10-01');
        $this->batch($withBatch, 2, 100, 888, '2026-10-05');

        $prices = Product::sellingPricesFor(collect([$withBatch->fresh(), $withoutBatch->fresh()]));

        $this->assertSame($withBatch->fresh()->currentSellingPrice(), $prices[$withBatch->id]);
        $this->assertSame($withoutBatch->fresh()->currentSellingPrice(), $prices[$withoutBatch->id]);
    }

    public function test_a_group_image_shows_for_variants_without_their_own(): void
    {
        $shop = $this->shop();
        $group = $this->group($shop);
        $group->update(['image_path' => 'shop-1/products/group.jpg']);
        [$plain, $withOwn] = $group->variants()->get()->all();
        $withOwn->update(['image_path' => 'shop-1/products/own.jpg']);

        $this->assertStringContainsString('group.jpg', (string) $plain->fresh()->imageUrl());
        $this->assertStringContainsString('own.jpg', (string) $withOwn->fresh()->imageUrl());
        $this->assertNull($this->standalone($shop)->imageUrl());
    }
}
