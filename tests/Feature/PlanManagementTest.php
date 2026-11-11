<?php

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    public function test_placeholder_plans_are_seeded_and_shown_on_the_pricing_page_in_order(): void
    {
        $this->assertSame(['Starter', 'Growth', 'Commerce'], Plan::ordered()->pluck('name')->all());
        $this->assertTrue(Plan::where('name', 'Growth')->value('is_featured'));

        $this->get('/pricing')
            ->assertOk()
            ->assertSeeInOrder(['Starter', 'Rs 2,500', 'Most popular', 'Growth', 'Rs 4,500', 'Commerce', 'Rs 7,500'])
            ->assertSee('/ month')
            ->assertSee('<span class="sr-only">Not included: </span>WhatsApp ordering', false);
    }

    public function test_an_inactive_plan_is_hidden_from_pricing_but_shops_on_it_keep_working(): void
    {
        $starter = Plan::where('name', 'Starter')->first();
        $shop = Shop::create(['name' => 'Shop A', 'plan_id' => $starter->id, 'plan_type' => 'monthly']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($this->superAdmin());
        Livewire::test('admin.plans')->call('toggleActive', $starter->id);
        $this->assertFalse($starter->fresh()->is_active);

        auth()->logout();
        $this->get('/pricing')->assertDontSee('>Starter</h2>', false)->assertSee('>Growth</h2>', false);

        $this->assertSame($starter->id, $shop->fresh()->plan_id);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_the_admin_creates_a_plan_and_its_pricing_lines_come_from_its_limits_and_features(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('admin.plans')
            ->call('openCreate')
            ->set('name', 'Enterprise')
            ->set('tagline', 'For chains of shops')
            ->set('price', '60000')
            ->set('billingInterval', 'yearly')
            ->set('limits.products', '5000')
            ->set('limits.staff_users', '25')
            ->set('limits.branches', '1')
            ->set('featureFlags.whatsapp_ordering', true)
            ->set('limited.whatsapp_orders_per_month', true)
            ->set('limits.whatsapp_orders_per_month', '100')
            ->assertSee('5,000 products')
            ->assertSee('100 orders a month')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::where('name', 'Enterprise')->sole();
        $this->assertSame(PlanType::Yearly, $plan->billing_interval);
        $this->assertSame([], $plan->features);
        $this->assertSame(4, $plan->sort_order);

        auth()->logout();
        $this->get('/pricing')->assertSeeInOrder(['Commerce', 'Enterprise', 'Rs 60,000', '/ year', '5,000 products', '25 staff users', 'Unlimited sales', '1 branch', 'WhatsApp ordering', '100 orders a month']);
    }

    public function test_pricing_lines_show_limits_and_list_only_features_some_plan_sells(): void
    {
        Plan::query()->delete();
        $basic = Plan::create(['name' => 'Basic', 'price' => 1000, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['products' => 500, 'staff_users' => 0, 'sales_per_month' => 1000, 'branches' => 1, 'marketing_posts_per_month' => 10], 'feature_flags' => ['social' => true, 'whatsapp_ordering' => false]]);
        $pro = Plan::create(['name' => 'Pro', 'price' => 2000, 'billing_interval' => 'monthly', 'features' => [], 'limits' => ['whatsapp_messages_per_month' => 0], 'feature_flags' => ['whatsapp_ordering' => true, 'social' => true, 'whatsapp_messaging' => true]]);

        $offered = Plan::offeredFeatures([$basic->fresh(), $pro->fresh()]);
        $this->assertSame(['whatsapp_ordering', 'social'], array_map(fn ($f) => $f->value, $offered), 'Messaging capped at 0 is not really offered; payroll is in no plan.');

        $lines = collect($basic->fresh()->pricingLines($offered))->keyBy('label');
        $this->assertSame(['500 products', 'Owner login only', '1,000 sales a month', '1 branch', 'Facebook & Instagram posting', 'WhatsApp ordering'], $lines->keys()->all(), 'Included lines first, then what the plan lacks.');
        $this->assertFalse($lines['WhatsApp ordering']['included']);
        $this->assertSame('10 posts a month', $lines['Facebook & Instagram posting']['detail']);

        $proLines = collect($pro->fresh()->pricingLines($offered))->keyBy('label');
        $this->assertSame('Unlimited products', $proLines->keys()->first());
        $this->assertSame('Unlimited orders', $proLines['WhatsApp ordering']['detail']);
        $this->assertSame('Unlimited posts', $proLines['Facebook & Instagram posting']['detail']);

        $this->get('/pricing')->assertSee('<span class="sr-only">Not included: </span>WhatsApp ordering', false);
    }

    public function test_plan_form_validation(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('admin.plans')
            ->call('openCreate')
            ->set('name', '')
            ->set('price', '-5')
            ->set('billingInterval', 'weekly')
            ->call('save')
            ->assertHasErrors(['name', 'price', 'billingInterval']);

        $this->assertSame(3, Plan::count());
    }

    public function test_editing_a_plan_updates_the_pricing_page(): void
    {
        $this->actingAs($this->superAdmin());
        $growth = Plan::where('name', 'Growth')->first();

        Livewire::test('admin.plans')
            ->call('openEdit', $growth->id)
            ->assertSet('name', 'Growth')
            ->assertSet('price', '4500')
            ->set('price', '3999')
            ->set('isFeatured', false)
            ->set('limits.products', '1234')
            ->call('save')
            ->assertHasNoErrors();

        $growth->refresh();
        $this->assertSame('3999.00', $growth->price);
        $this->assertFalse($growth->is_featured);

        auth()->logout();
        $this->get('/pricing')->assertSee('Rs 3,999')->assertDontSee('Most popular')->assertSee('1,234 products')->assertDontSee('Everything in Starter');
    }

    public function test_plans_can_be_reordered(): void
    {
        $this->actingAs($this->superAdmin());
        $commerce = Plan::where('name', 'Commerce')->first();

        Livewire::test('admin.plans')->call('move', $commerce->id, 'up')->call('move', $commerce->id, 'up')->call('move', $commerce->id, 'up');

        $this->assertSame(['Commerce', 'Starter', 'Growth'], Plan::ordered()->pluck('name')->all());
        $this->assertSame([1, 2, 3], Plan::ordered()->pluck('sort_order')->all());

        auth()->logout();
        $this->get('/pricing')->assertSeeInOrder(['Commerce', 'Starter', 'Growth']);
    }

    public function test_a_plan_with_shops_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->actingAs($this->superAdmin());
        $growth = Plan::where('name', 'Growth')->first();
        $starter = Plan::where('name', 'Starter')->first();
        Shop::create(['name' => 'Shop A', 'plan_id' => $growth->id]);

        Livewire::test('admin.plans')->call('delete', $growth->id)->call('delete', $starter->id);

        $this->assertNotNull($growth->fresh());
        $this->assertNull($starter->fresh());

        $this->expectException(QueryException::class);
        $growth->delete();
    }

    public function test_only_the_super_admin_can_manage_plans(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $growth = Plan::where('name', 'Growth')->first();

        $this->actingAs($owner)->get(route('admin.plans'))->assertForbidden();

        Livewire::actingAs($owner)->test('admin.plans')->call('toggleActive', $growth->id)->assertForbidden();
        $this->assertTrue($growth->fresh()->is_active);

        $this->actingAs($this->superAdmin())->get(route('admin.plans'))->assertOk()->assertSee('Growth');
    }

    public function test_assigning_a_plan_to_a_shop_sets_its_billing_interval(): void
    {
        $admin = $this->superAdmin();
        $shop = Shop::create(['name' => 'Shop A', 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->toDateString()]);
        $yearly = Plan::create(['name' => 'Annual', 'price' => 40000, 'billing_interval' => 'yearly', 'features' => [['label' => 'All features', 'included' => true]], 'sort_order' => 9]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->set('planId', (string) $yearly->id)
            ->assertSet('planType', 'yearly')
            ->call('saveSubscription')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame($yearly->id, $shop->plan_id);
        $this->assertSame(PlanType::Yearly, $shop->plan_type);
        $this->assertSame(now()->addYear()->toDateString(), $shop->expectedRenewalDate()->toDateString());

        $this->get(route('admin.dashboard'))->assertSee('Annual');

        Livewire::test('admin.shops.show', ['shop' => $shop])->set('planId', '')->call('saveSubscription');
        $this->assertNull($shop->fresh()->plan_id);
        $this->assertSame(PlanType::Yearly, $shop->fresh()->plan_type);
    }

    public function test_pricing_page_falls_back_to_contact_when_no_plan_is_active(): void
    {
        Plan::query()->update(['is_active' => false]);

        $this->get('/pricing')->assertOk()->assertSee('Plans are being updated')->assertSee(route('site.contact'));
    }
}
