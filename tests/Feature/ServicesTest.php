<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\UdhaarReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function enabledShopWithOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        return [$shop, $owner];
    }

    public function test_services_is_disabled_by_default_for_a_new_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $this->assertFalse($shop->fresh()->services_enabled);
    }

    public function test_super_admin_can_turn_services_on_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleServicesEnabled');

        $this->assertTrue($shopA->fresh()->services_enabled);
        $this->assertFalse($shopB->fresh()->services_enabled);
    }

    public function test_the_sidebar_does_not_show_services_when_the_shop_feature_is_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('services.index', $html);
    }

    public function test_the_sidebar_shows_services_once_the_shop_feature_is_on(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertSee('Services');
    }

    public function test_visiting_the_services_screen_directly_by_url_is_blocked_when_the_shop_feature_is_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('services.index'))->assertStatus(403);
        $this->get(route('services.history'))->assertStatus(403);
    }

    public function test_the_settings_services_tab_is_absent_when_the_shop_feature_is_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $html = $this->get(route('settings.index'))->getContent();

        $this->assertStringNotContainsString("setTab('services')", $html);
    }

    public function test_the_settings_services_tab_appears_once_the_shop_feature_is_on(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $this->actingAs($owner);

        $this->get(route('settings.index'))->assertSee("setTab('services')", false);
    }

    public function test_default_service_categories_are_seeded_the_first_time_the_screen_is_visited(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $this->actingAs($owner);

        $this->assertSame(0, ServiceCategory::count());

        Livewire::test('services.create');

        $this->assertSame(['Downloads', 'Installations', 'Services'], ServiceCategory::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_creating_a_service_category_is_shop_scoped(): void
    {
        [$shopA, $ownerA] = $this->enabledShopWithOwner();
        $shopB = Shop::create(['name' => 'Shop B', 'services_enabled' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);

        $this->actingAs($ownerA);
        Livewire::test('settings.index')
            ->set('tab', 'services')
            ->set('serviceCategoryName', 'Repairs Add-on')
            ->call('saveServiceCategory')
            ->assertHasNoErrors();

        $this->assertSame(1, ServiceCategory::where('shop_id', $shopA->id)->where('name', 'Repairs Add-on')->count());

        $this->actingAs($ownerB);
        $this->assertSame(0, ServiceCategory::where('shop_id', $shopB->id)->where('name', 'Repairs Add-on')->count());
    }

    public function test_creating_a_service_item_under_a_category_is_shop_scoped(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'services')
            ->set('serviceItemCategoryId', (string) $category->id)
            ->set('serviceItemName', 'Screen Protector Install')
            ->call('saveServiceItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_items', [
            'shop_id' => $shop->id,
            'service_category_id' => $category->id,
            'name' => 'Screen Protector Install',
        ]);
    }

    public function test_deleting_a_category_with_items_under_it_is_blocked(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'services')
            ->call('deleteServiceCategory', $category->id);

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    }

    public function test_deleting_an_item_with_payment_history_is_blocked(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        ServicePayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'service_category_id' => $category->id, 'service_item_id' => $item->id, 'amount' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'services')
            ->call('deleteServiceItem', $item->id);

        $this->assertDatabaseHas('service_items', ['id' => $item->id]);
    }

    public function test_logging_a_complete_service_payment_does_not_touch_udhaar(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $this->actingAs($owner);

        Livewire::test('services.create')
            ->set('serviceCategoryId', (string) $category->id)
            ->set('serviceItemId', (string) $item->id)
            ->set('customerId', (string) $customer->id)
            ->set('amount', '1000')
            ->set('paymentStatus', 'paid')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0.0, (new UdhaarReport)->totalDue());
    }

    public function test_logging_a_partial_service_payment_shows_up_as_owed_in_udhaar_exactly_like_a_bill_payment(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $this->actingAs($owner);

        Livewire::test('services.create')
            ->set('serviceCategoryId', (string) $category->id)
            ->set('serviceItemId', (string) $item->id)
            ->set('customerId', (string) $customer->id)
            ->set('amount', '1000')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '400')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(600.0, (new UdhaarReport)->totalDue(), 0.01);
    }

    public function test_an_unpaid_service_payment_with_no_customer_is_a_walk_in_and_never_reaches_udhaar(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $this->actingAs($owner);

        Livewire::test('services.create')
            ->set('serviceCategoryId', (string) $category->id)
            ->set('serviceItemId', (string) $item->id)
            ->set('amount', '1000')
            ->set('paymentStatus', 'unpaid')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0.0, (new UdhaarReport)->totalDue());
    }

    public function test_cancelling_a_partial_service_payment_removes_it_from_udhaar(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $payment = ServicePayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'service_category_id' => $category->id, 'service_item_id' => $item->id,
            'amount' => 1000, 'payment_status' => 'partial', 'amount_paid' => 400,
        ]);
        $this->actingAs($owner);

        $this->assertEqualsWithDelta(600.0, (new UdhaarReport)->totalDue(), 0.01);

        Livewire::test('services.history')
            ->call('openCancel', $payment->id)
            ->call('cancelPayment');

        $this->assertTrue($payment->fresh()->isCancelled());
        $this->assertSame(0.0, (new UdhaarReport)->totalDue());
    }

    public function test_marking_a_service_payment_as_paid_zeroes_out_what_is_owed(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $payment = ServicePayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id,
            'service_category_id' => $category->id, 'service_item_id' => $item->id,
            'amount' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);
        $this->actingAs($owner);

        Livewire::test('services.history')->call('markAsPaid', $payment->id);

        $payment->refresh();
        $this->assertSame('paid', $payment->payment_status->value);
        $this->assertEqualsWithDelta(1000.0, (float) $payment->amount_paid, 0.01);
        $this->assertSame(0.0, $payment->amountOwed());
    }

    public function test_editing_a_service_payment_requires_the_edit_cancel_permission(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $payment = ServicePayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'service_category_id' => $category->id, 'service_item_id' => $item->id, 'amount' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['services']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('services.history')->call('openEdit', $payment->id)->assertStatus(403);
        Livewire::test('services.history')->call('openCancel', $payment->id)->assertStatus(403);
    }

    public function test_a_role_with_the_services_edit_cancel_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner] = $this->enabledShopWithOwner();
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $payment = ServicePayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'service_category_id' => $category->id, 'service_item_id' => $item->id, 'amount' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['services', 'services-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('services.history')
            ->call('openEdit', $payment->id)
            ->set('editAmount', '1200')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(1200.0, (float) $payment->fresh()->amount, 0.01);
    }

    public function test_this_module_has_zero_interaction_with_products_stock_in_or_vendors(): void
    {
        $files = [
            app_path('Models/ServiceCategory.php'),
            app_path('Models/ServiceItem.php'),
            app_path('Models/ServicePayment.php'),
            app_path('Actions/CreateServiceCategory.php'),
            app_path('Actions/CreateServiceItem.php'),
            app_path('Actions/CancelServicePayment.php'),
            app_path('Actions/UpdateServicePayment.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('ConsumeStockFifo', $contents);
            $this->assertStringNotContainsString('StockIn', $contents);
            $this->assertStringNotContainsString('Vendor', $contents);
            $this->assertStringNotContainsString('Product', $contents);
        }
    }
}
