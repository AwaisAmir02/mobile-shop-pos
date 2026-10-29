<?php

namespace Tests\Feature;

use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\ShopAddonStatus;
use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AddonsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    protected function plannedShop(array $limits = ['products' => 2], array $features = ['whatsapp' => false], string $interval = 'monthly'): array
    {
        $plan = Plan::create(['name' => 'Plan '.uniqid(), 'price' => 2000, 'billing_interval' => $interval, 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $features]);
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => $interval, 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        return [$shop, $owner, $plan];
    }

    protected function unlock(string $feature = 'whatsapp', string $billing = 'monthly'): Addon
    {
        return Addon::create(['name' => 'WhatsApp ordering', 'kind' => 'feature_unlock', 'feature_key' => $feature, 'price' => 1500, 'billing' => $billing])->fresh();
    }

    protected function confirmLatestPayment(): SubscriptionPayment
    {
        return app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $this->admin()->id);
    }

    public function test_the_example_pack_is_seeded_and_only_the_super_admin_manages_the_catalogue(): void
    {
        $pack = Addon::sole();
        $this->assertSame('Extra 500 products', $pack->name);
        $this->assertSame(AddonKind::LimitPack, $pack->kind);
        $this->assertSame(LimitKey::Products, $pack->limit_key);
        $this->assertSame(AddonBilling::OneOff, $pack->billing);

        [, $owner] = $this->plannedShop();
        $this->actingAs($owner)->get(route('admin.addons'))->assertForbidden();
        Livewire::actingAs($owner)->test('admin.addons')->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.addons'))->assertOk()->assertSee('Extra 500 products')->assertSee('+500 products');
    }

    public function test_the_admin_creates_packs_and_unlocks_with_the_right_billing(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'More messages')->set('kind', 'limit_pack')->set('limitKey', 'whatsapp_messages_per_month')->set('price', '800')->call('save')->assertHasErrors(['quantity']);

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'More messages')->set('kind', 'limit_pack')->set('limitKey', 'whatsapp_messages_per_month')->set('quantity', '1000')->set('price', '800')->set('billing', 'yearly')->call('save')->assertHasNoErrors();
        $pack = Addon::where('name', 'More messages')->sole();
        $this->assertSame(AddonBilling::OneOff, $pack->billing);
        $this->assertNull($pack->feature_key);

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'Ads unlock')->set('kind', 'feature_unlock')->set('featureKey', 'ads')->set('billing', 'yearly')->set('price', '9000')->call('save')->assertHasNoErrors();
        $unlock = Addon::where('name', 'Ads unlock')->sole();
        $this->assertSame(AddonBilling::Yearly, $unlock->billing);
        $this->assertSame(FeatureKey::Ads, $unlock->feature_key);
        $this->assertNull($unlock->quantity);
    }

    public function test_an_owner_buys_the_pack_pays_and_gets_more_room_without_touching_the_plan_dates(): void
    {
        [$shop, $owner] = $this->plannedShop();
        $startedOn = $shop->subscription_start_date->toDateString();
        $pack = Addon::sole();

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('Extra 500 products')->assertSee('Rs 1,000 one-off');

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $pack->id)->assertHasNoErrors();

        $shopAddon = ShopAddon::sole();
        $this->assertSame(ShopAddonStatus::Pending, $shopAddon->status);
        $this->assertSame('1000.00', $shopAddon->invoice->total);
        $this->assertSame('Add-on: Extra 500 products', $shopAddon->invoice->notes);
        $this->assertSame(2, app(Entitlements::class)->limit($shop, LimitKey::Products));

        $this->actingAs($owner)->get(route('dashboard'))->assertSee('to activate your Extra 500 products add-on');

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $pack->id);
        $this->assertSame(1, ShopAddon::count());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.payments'))->assertSee('Extra 500 products')->assertSee('Add-on');
        Livewire::actingAs($admin)->test('admin.payments')->call('confirm', SubscriptionPayment::sole()->id);

        $shopAddon->refresh();
        $this->assertSame(ShopAddonStatus::Active, $shopAddon->status);
        $this->assertSame($shop->expectedRenewalDate()->toDateString(), $shopAddon->ends_at->toDateString());
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $shopAddon->invoice->fresh()->status);
        $this->assertSame(502, app(Entitlements::class)->limit($shop, LimitKey::Products));
        $this->assertSame($startedOn, $shop->fresh()->subscription_start_date->toDateString());

        $this->travelTo($shopAddon->ends_at->copy()->addMinute());
        $this->artisan('billing:expire-addons')->assertSuccessful();
        $this->assertSame(ShopAddonStatus::Expired, $shopAddon->fresh()->status);
        $this->assertSame(2, app(Entitlements::class)->limit($shop, LimitKey::Products));
    }

    public function test_a_feature_unlock_switches_the_feature_on_and_off_and_renewal_extends_it(): void
    {
        [$shop, $owner] = $this->plannedShop(features: ['whatsapp' => false]);
        $unlock = $this->unlock();
        $addons = app(AddonService::class);

        $addons->purchase($shop, $unlock, $owner->id);
        $this->assertFalse($shop->fresh()->whatsapp_enabled);

        $this->confirmLatestPayment();
        $this->assertTrue($shop->fresh()->whatsapp_enabled);
        $first = ShopAddon::sole();
        $this->assertTrue($first->ends_at->isSameDay(now()->addMonthNoOverflow()));

        $addons->purchase($shop->fresh(), $unlock, $owner->id);
        $this->confirmLatestPayment();
        $second = ShopAddon::latest('id')->first();
        $this->assertTrue($second->ends_at->isSameDay($first->ends_at->copy()->addMonthNoOverflow()));

        $this->travelTo($first->ends_at->copy()->addMinute());
        $this->artisan('billing:expire-addons');
        $this->assertTrue($shop->fresh()->whatsapp_enabled);

        $this->travelTo($second->ends_at->copy()->addMinute());
        $this->artisan('billing:expire-addons');
        $this->assertFalse($shop->fresh()->whatsapp_enabled);
    }

    public function test_an_admin_override_beats_an_unlock(): void
    {
        [$shop, $owner] = $this->plannedShop(features: ['whatsapp' => false]);
        app(AddonService::class)->purchase($shop, $this->unlock(), $owner->id);
        $this->confirmLatestPayment();

        app(Entitlements::class)->setFeatureOverride($shop->fresh(), FeatureKey::WhatsApp, false);
        $this->assertFalse($shop->fresh()->whatsapp_enabled);

        app(Entitlements::class)->setFeatureOverride($shop->fresh(), FeatureKey::WhatsApp, null);
        $this->assertTrue($shop->fresh()->whatsapp_enabled);
    }

    public function test_unlocks_already_in_the_plan_are_not_offered_and_shops_without_a_plan_cannot_buy(): void
    {
        [$shop, $owner] = $this->plannedShop(features: ['whatsapp' => true]);
        $unlock = $this->unlock();

        $this->actingAs($owner)->get(route('billing.index'))->assertDontSee('WhatsApp ordering')->assertSee('Extra 500 products');

        try {
            app(AddonService::class)->purchase($shop, $unlock, $owner->id);
            $this->fail('Expected the purchase to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already includes', $e->getMessage());
        }

        $plain = Shop::create(['name' => 'No plan']);
        $this->expectExceptionMessage('once your shop is on a plan');
        app(AddonService::class)->purchase($plain, Addon::where('kind', 'limit_pack')->first(), null);
    }

    public function test_a_rejected_add_on_payment_can_be_resubmitted_and_hidden_add_ons_cannot_be_bought(): void
    {
        [$shop, $owner] = $this->plannedShop();
        $pack = Addon::sole();
        app(AddonService::class)->purchase($shop, $pack, $owner->id);
        $payments = app(SubscriptionPaymentService::class);
        $admin = $this->admin();

        $payments->submitProof(SubscriptionPayment::sole(), 'TID-1', null);
        $payments->reject(SubscriptionPayment::sole(), $admin->id, 'Not found');
        $this->assertSame(ShopAddonStatus::Pending, ShopAddon::sole()->status);

        $payments->submitProof(SubscriptionPayment::sole(), 'TID-2', null);
        $payments->confirm(SubscriptionPayment::sole(), $admin->id);
        $this->assertSame(ShopAddonStatus::Active, ShopAddon::sole()->status);

        $this->actingAs($admin);
        Livewire::test('admin.addons')->call('delete', $pack->id);
        $this->assertNotNull($pack->fresh());
        Livewire::test('admin.addons')->call('toggleActive', $pack->id);
        $this->assertFalse($pack->fresh()->is_active);
        $this->assertSame(ShopAddonStatus::Active, ShopAddon::sole()->status);

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $pack->id);
        $this->assertSame(1, ShopAddon::count());
    }
}
