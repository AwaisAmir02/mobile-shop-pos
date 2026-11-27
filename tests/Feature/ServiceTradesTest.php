<?php

namespace Tests\Feature;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;
use App\Enums\OnlineTool;
use App\Enums\ServiceTradeTool;
use App\Enums\WholesaleTool;
use App\Http\Middleware\EnsureShopUsesTool;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** The Services group itself: categories, related-trade combinations, tools and gating. */
class ServiceTradesTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    public function test_the_group_is_offered_at_signup_with_its_five_trades(): void
    {
        $this->assertContains(BusinessGroup::ServiceTrades, BusinessGroup::cases());
        $this->assertSame(['repair_workshop', 'salon', 'spa', 'gym', 'personal_training'], array_map(fn ($c) => $c->value, BusinessGroup::ServiceTrades->signupCases()));
        $this->assertSame(BusinessGroup::ServiceTrades, BusinessCategory::Gym->group());
        $this->assertNotSame('services', BusinessGroup::ServiceTrades->value);
    }

    public function test_tool_values_are_unique_across_all_three_tool_enums(): void
    {
        $values = array_merge(array_column(WholesaleTool::cases(), 'value'), array_column(OnlineTool::cases(), 'value'), array_column(ServiceTradeTool::cases(), 'value'));
        $this->assertSame(count($values), count(array_unique($values)));

        foreach (ServiceTradeTool::cases() as $tool) {
            $this->assertSame($tool, EnsureShopUsesTool::tool($tool->value));
        }
    }

    public function test_only_related_trades_combine(): void
    {
        $this->assertSame([BusinessCategory::Spa], BusinessCategory::Salon->combinesWith());
        $this->assertSame([BusinessCategory::PersonalTraining], BusinessCategory::Gym->combinesWith());
        $this->assertSame([], BusinessCategory::RepairWorkshop->combinesWith());
        $this->assertSame([], BusinessCategory::Kiryana->combinesWith());

        $this->openServiceShop('salon', ['business_addons' => ['spa', 'gym', 'kiryana']]);
        $this->assertSame([BusinessCategory::Spa], $this->shop->fresh()->businessAddons(), 'Unrelated trades are ignored even if stored.');
    }

    public function test_a_combined_shop_gets_the_tools_of_both_trades(): void
    {
        $this->openServiceShop('personal_training');
        $this->assertFalse($this->shop->usesTool(ServiceTradeTool::Biometric));

        $this->shop->update(['business_addons' => ['gym']]);
        $this->assertTrue($this->shop->fresh()->usesTool(ServiceTradeTool::Biometric));
    }

    #[DataProvider('trades')]
    public function test_each_trade_gets_its_tools(string $category, array $has, array $lacks): void
    {
        $shop = Shop::create(['name' => 'T', 'business_category' => $category]);

        foreach ($has as $tool) {
            $this->assertTrue($shop->usesTool(ServiceTradeTool::from($tool)), "{$category} should use {$tool}");
        }

        foreach ($lacks as $tool) {
            $this->assertFalse($shop->usesTool(ServiceTradeTool::from($tool)), "{$category} should not use {$tool}");
        }
    }

    public static function trades(): array
    {
        return [
            'workshop' => ['repair_workshop', ['job_cards', 'deposits', 'commission'], ['appointments', 'memberships', 'check_ins']],
            'salon' => ['salon', ['service_menu', 'appointments', 'walk_ins', 'memberships', 'vouchers', 'deposits', 'client_notes', 'broadcasts'], ['job_cards', 'check_ins', 'biometric']],
            'gym' => ['gym', ['memberships', 'check_ins', 'biometric', 'broadcasts', 'appointments'], ['job_cards', 'vouchers', 'walk_ins']],
            'kiryana' => ['kiryana', [], ['service_menu', 'job_cards', 'memberships']],
            'distributor' => ['distributor', [], ['service_menu', 'commission']],
        ];
    }

    public function test_screens_404_without_the_tool_and_open_with_it(): void
    {
        $this->openServiceShop('salon');

        foreach (['service-menu.index', 'appointments.index', 'memberships.index', 'gift-vouchers.index', 'staff.index', 'staff-earnings.index', 'broadcasts.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        foreach (['job-cards.index', 'check-ins.index', 'biometric-devices.index'] as $route) {
            $this->get(route($route))->assertNotFound();
        }

        $retail = Shop::create(['name' => 'Mobile', 'business_category' => 'mobile_electronics']);
        $this->actingAs(User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $this->get(route('appointments.index'))->assertNotFound();
        $this->get(route('job-cards.index'))->assertNotFound();
    }

    public function test_defaults_hide_the_mobile_modules_and_add_starter_categories(): void
    {
        $this->openServiceShop('gym');
        app(BusinessCategories::class)->applyDefaults($this->shop);

        $this->assertContains('repairs', BusinessCategories::hiddenModules($this->shop->fresh()));
        $this->assertTrue(\App\Models\ServiceCategory::where('name', 'Memberships')->exists());
    }

    public function test_the_sidebar_shows_the_trades_screens_only(): void
    {
        $this->openServiceShop('repair_workshop');
        $this->get(route('dashboard'))->assertOk()->assertSee('Job Cards')->assertDontSee('Appointments');

        $this->openServiceShop('gym');
        $this->get(route('dashboard'))->assertOk()->assertSee('Check-ins')->assertSee('Members')->assertDontSee('Job Cards');
    }

    public function test_signup_takes_a_related_trade_alongside(): void
    {
        $component = \Livewire\Volt\Volt::test('signup.form')
            ->set('businessGroup', 'service_trades')
            ->set('businessCategory', 'salon');

        $component->assertSee('Spa')->set('businessAddons', ['spa', 'gym']);
        $this->assertSame(['spa'], \App\Enums\BusinessCategory::Salon->cleanAddons(['spa', 'gym']));
    }
}
