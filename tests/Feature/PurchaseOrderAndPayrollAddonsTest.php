<?php

namespace Tests\Feature;

use App\Actions\Payroll;
use App\Actions\SavePurchaseOrder;
use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\ShopAddonStatus;
use App\Models\Addon;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Billing\AddonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseOrderAndPayrollAddonsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: User} */
    protected function shopOn(string $planName): array
    {
        $plan = Plan::where('name', $planName)->sole();
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
        app(\App\Services\Billing\Entitlements::class)->syncFeatureFlags($shop);

        return [$shop->fresh(), User::factory()->create(['shop_id' => $shop->id])];
    }

    protected function confirmLatestPayment(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);

        app(\App\Services\Billing\SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $admin->id);
    }

    public static function addons(): array
    {
        return [
            'purchase orders' => ['Purchase Orders', FeatureKey::PurchaseOrders, 'purchase-orders.index'],
            'payroll' => ['Payroll', FeatureKey::Payroll, 'payroll.employees'],
        ];
    }

    #[DataProvider('addons')]
    public function test_it_is_seeded_at_rs_1000_a_month_and_listed_for_the_admin(string $name, FeatureKey $feature, string $route): void
    {
        $addon = Addon::where('name', $name)->sole();

        $this->assertSame(AddonKind::FeatureUnlock, $addon->kind);
        $this->assertSame(AddonBilling::Monthly, $addon->billing);
        $this->assertSame('1000.00', $addon->price);
        $this->assertTrue($addon->is_active);
        $this->assertSame([$feature], $addon->feature_keys->all());

        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $this->actingAs($admin)->get(route('admin.addons'))->assertOk()->assertSee($name)->assertSee($addon->priceLabel());
    }

    #[DataProvider('addons')]
    public function test_a_starter_shop_buys_it_and_the_feature_turns_on_then_off_when_it_lapses(string $name, FeatureKey $feature, string $route): void
    {
        [$shop, $owner] = $this->shopOn('Starter');
        $addon = Addon::where('name', $name)->sole();

        $this->assertFalse($shop->{$feature->column()});
        $this->actingAs($owner)->get(route('billing.index'))->assertSee($name)->assertSee('buyAddon('.$addon->id.')', false)->assertSee($addon->priceLabel());

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $addon->id)->assertHasNoErrors();

        $purchase = ShopAddon::sole();
        $this->assertSame(ShopAddonStatus::Pending, $purchase->status);
        $this->assertSame('1000.00', $purchase->invoice->total);
        $this->assertFalse($shop->fresh()->{$feature->column()}, 'Stays off until the payment is confirmed.');

        $this->confirmLatestPayment();

        $purchase->refresh();
        $this->assertSame(ShopAddonStatus::Active, $purchase->status);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $purchase->ends_at->toDateString());
        $this->assertTrue($shop->fresh()->{$feature->column()});

        $owner = $owner->fresh();
        $this->actingAs($owner)->get(route($route))->assertOk();
        $this->actingAs($owner)->get(route('dashboard'))->assertSee(route($route));

        $this->travel(32)->days();
        app(AddonService::class)->expireDue();

        $this->assertFalse($shop->fresh()->{$feature->column()});
    }

    public function test_payroll_can_really_be_run_only_once_the_add_on_is_active(): void
    {
        [$shop, $owner] = $this->shopOn('Starter');
        $this->actingAs($owner);
        Employee::create(['shop_id' => $shop->id, 'name' => 'Ali', 'rate' => 30000]);

        try {
            Payroll::generate($shop->id, '2026-11', null, $owner->id);
            $this->fail('Should be refused before the add-on.');
        } catch (ValidationException) {
        }

        app(AddonService::class)->purchase($shop, Addon::where('name', 'Payroll')->sole(), $owner->id);
        $this->confirmLatestPayment();

        $this->assertSame(1, Payroll::generate($shop->id, '2026-11', null, $owner->id));
    }

    public function test_a_purchase_order_can_really_be_saved_only_once_the_add_on_is_active(): void
    {
        [$shop, $owner] = $this->shopOn('Starter');
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'grocery', 'name' => 'Tea', 'price' => 900, 'cost_price' => 700, 'stock_quantity' => 0, 'details' => ['category' => null]]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Karachi Traders']);
        $save = fn () => SavePurchaseOrder::handle(null, ['vendor_id' => $vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $product->id, 'quantity' => 5, 'expected_cost' => 700.0]], $owner->id);

        try {
            $save();
            $this->fail('Should be refused before the add-on.');
        } catch (ValidationException) {
        }

        app(AddonService::class)->purchase($shop, Addon::where('name', 'Purchase Orders')->sole(), $owner->id);
        $this->confirmLatestPayment();

        $this->assertSame($vendor->id, $save()->vendor_id);
    }

    public function test_a_real_downgrade_turns_the_features_off_and_payroll_history_stays(): void
    {
        [$shop, $owner] = $this->shopOn('Commerce');
        $this->assertTrue($shop->payroll_enabled);
        $this->assertTrue($shop->purchase_orders_enabled);

        $this->actingAs($owner);
        Employee::create(['shop_id' => $shop->id, 'name' => 'Ali', 'rate' => 30000]);
        Payroll::generate($shop->id, '2026-11', null, $owner->id);

        $invoice = app(\App\Services\SubscriptionBillingService::class)->create($shop, ['period_start' => now()->toDateString(), 'months' => 1, 'amount' => 1000, 'notes' => 'Plan change', 'plan_id' => Plan::where('name', 'Starter')->sole()->id], $owner->id);
        $invoice->forceFill(['plan_id' => Plan::where('name', 'Starter')->sole()->id])->save();
        app(\App\Services\Billing\PlanChangeService::class)->apply($invoice);

        $shop = $shop->fresh();
        $this->assertSame('Starter', $shop->plan->name);
        $this->assertFalse($shop->payroll_enabled);
        $this->assertFalse($shop->purchase_orders_enabled);

        $this->actingAs($owner->fresh())->get(route('payroll.history'))->assertOk()->assertSee('Ali');
    }

    public function test_plans_that_include_a_feature_are_not_offered_its_add_on(): void
    {
        $po = Addon::where('name', 'Purchase Orders')->sole();
        $payroll = Addon::where('name', 'Payroll')->sole();

        [, $commerce] = $this->shopOn('Commerce');
        $this->actingAs($commerce)->get(route('billing.index'))->assertDontSee('buyAddon('.$po->id.')', false)->assertDontSee('buyAddon('.$payroll->id.')', false);

        [, $growth] = $this->shopOn('Growth');
        $this->actingAs($growth)->get(route('billing.index'))->assertDontSee('buyAddon('.$payroll->id.')', false);
    }
}
