<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepairCategoryAndPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    // ── Main / Sub-Category selectors ────────────────────────────────

    public function test_a_repair_can_be_saved_with_a_main_and_sub_category_from_the_shared_taxonomy(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Charger']);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('mainCategorySlug', 'accessory')
            ->set('subCategoryName', 'Charger')
            ->set('description', 'Charger not powering on')
            ->set('amount', '200')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame('accessory', $repair->category);
        $this->assertSame('Charger', $repair->sub_category);
        $this->assertSame('Accessory · Charger', $repair->categoryLabel());
    }

    public function test_a_custom_main_category_can_be_used_for_a_repair_without_a_product_record(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Laptops', 'slug' => 'laptops']);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('mainCategorySlug', 'laptops')
            ->set('description', 'Dell XPS, keyboard replacement')
            ->set('amount', '3000')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame('laptops', $repair->category);
        $this->assertSame('Laptops', $repair->categoryLabel());
        $this->assertSame(0, Product::count());
    }

    public function test_selecting_new_main_category_opens_the_quick_create_modal_and_reverts_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->assertSet('mainCategorySlug', 'mobile')
            ->set('mainCategorySlug', '__create__')
            ->assertSet('mainCategorySlug', 'mobile')
            ->assertDispatched('open-modal', name: 'quick-create-main-category');
    }

    public function test_creating_a_main_category_inline_from_repairs_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $repairs = Livewire::test('repairs.create');
        $quickCreate = Livewire::test('main-categories.quick-create');

        $quickCreate->set('name', 'Laptops')->call('save')->assertHasNoErrors();

        $category = MainCategory::where('name', 'Laptops')->firstOrFail();

        $repairs->call('onMainCategoryCreated', $category->slug);
        $repairs->assertSet('mainCategorySlug', $category->slug);
    }

    public function test_selecting_new_sub_category_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('subCategoryName', '__create__')
            ->assertSet('subCategoryName', '')
            ->assertDispatched('open-modal', name: 'quick-create-accessory-category');
    }

    public function test_creating_a_sub_category_inline_from_repairs_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::ensureDefaultsExist($shop->id);

        $this->actingAs($owner);

        $repairs = Livewire::test('repairs.create')->set('mainCategorySlug', 'accessory');
        $quickCreate = Livewire::test('accessory-categories.quick-create', ['defaultMainCategorySlug' => 'accessory']);

        $quickCreate->set('name', 'Motherboard')->call('save')->assertHasNoErrors();

        $repairs->call('onAccessoryCategoryCreated', 'Motherboard');
        $repairs->assertSet('subCategoryName', 'Motherboard');
    }

    public function test_the_history_screen_can_filter_by_main_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Mobile repair job', 'amount' => 100, 'discount' => 0, 'total' => 100]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'accessory', 'description' => 'Accessory repair job', 'amount' => 200, 'discount' => 0, 'total' => 200]);

        $this->actingAs($owner);

        Livewire::test('repairs.history')
            ->set('category', 'mobile')
            ->assertSee('Mobile repair job')
            ->assertDontSee('Accessory repair job');
    }

    // ── Payment Status ──────────────────────────────────────────────

    public function test_a_repair_defaults_to_paid_and_owes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Screen replacement')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame('paid', $repair->payment_status->value);
        $this->assertEquals(1000, $repair->amount_paid);
        $this->assertSame(0.0, $repair->amountOwed());
    }

    public function test_a_partial_repair_requires_and_stores_the_amount_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Screen replacement')
            ->set('amount', '1000')
            ->set('paymentStatus', 'partial')
            ->call('save')
            ->assertHasErrors(['amountPaid']);

        Livewire::test('repairs.create')
            ->set('description', 'Screen replacement')
            ->set('amount', '1000')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '400')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame('partial', $repair->payment_status->value);
        $this->assertEquals(400, $repair->amount_paid);
        $this->assertEquals(600, $repair->amountOwed());
    }

    public function test_marking_a_repair_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $repair = Repair::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'X',
            'amount' => 1000, 'discount' => 0, 'total' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('repairs.history')->call('markAsPaid', $repair->id);

        $repair->refresh();
        $this->assertSame('paid', $repair->payment_status->value);
        $this->assertEquals(1000, $repair->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $repair = Repair::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'X',
            'amount' => 1000, 'discount' => 0, 'total' => 1000, 'payment_status' => 'partial', 'amount_paid' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('repairs.history')
            ->call('openAddPayment', $repair->id)
            ->set('paymentAmount', '700')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $repair->refresh();
        $this->assertSame('paid', $repair->payment_status->value);
        $this->assertEquals(1000, $repair->amount_paid);
    }

    public function test_repair_tenant_isolation_covers_category_and_payment_status(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        Repair::create(['shop_id' => $shopA->id, 'category' => 'mobile', 'description' => 'Shop A job', 'amount' => 100, 'discount' => 0, 'total' => 100, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        Repair::create(['shop_id' => $shopB->id, 'category' => 'mobile', 'description' => 'Shop B job', 'amount' => 9999, 'discount' => 0, 'total' => 9999, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $this->actingAs($ownerA);

        Livewire::test('repairs.history')
            ->assertSee('Shop A job')
            ->assertDontSee('Shop B job')
            ->assertDontSee('9,999.00');
    }
}
