<?php

namespace Tests\Feature;

use App\Enums\BusinessCategory;
use App\Enums\OnlineTool;
use App\Enums\WholesaleTool;
use App\Http\Middleware\EnsureShopUsesTool;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O0: which trades get the Online P1 tools, the one shop.tool middleware for
 * both tool enums, and the screens and links behind them.
 */
class OnlineToolsTest extends TestCase
{
    use RefreshDatabase;

    public static function categories(): array
    {
        return [
            'social seller' => ['social_seller', true],
            'daraz seller' => ['daraz_seller', true],
            'online brand' => ['online_brand', true],
            'mobile shop' => ['mobile_electronics', false],
            'kiryana' => ['kiryana', false],
            'clothing' => ['clothing', false],
            'wholesaler' => ['wholesaler', false],
            'distributor' => ['distributor', false],
        ];
    }

    #[DataProvider('categories')]
    public function test_only_online_trades_use_the_online_tools(string $category, bool $online): void
    {
        $shop = Shop::create(['name' => 'Shop', 'business_category' => $category]);

        $this->assertSame($online, $shop->isOnline());
        $this->assertSame($online ? OnlineTool::cases() : [], BusinessCategory::from($category)->onlineTools());

        foreach (OnlineTool::cases() as $tool) {
            $this->assertSame($online, $shop->usesTool($tool), $tool->value);
        }
    }

    public function test_a_wholesale_trade_keeps_its_own_tools_and_gets_no_online_ones(): void
    {
        $shop = Shop::create(['name' => 'Distributor', 'business_category' => 'distributor']);

        $this->assertTrue($shop->usesTool(WholesaleTool::SalesOrders));
        $this->assertFalse($shop->usesTool(OnlineTool::CourierSettlements));
        $this->assertFalse($shop->isOnline());
    }

    public function test_tool_values_never_repeat_across_the_two_enums_so_the_middleware_can_resolve_each(): void
    {
        $wholesale = array_map(fn (WholesaleTool $tool) => $tool->value, WholesaleTool::cases());
        $online = array_map(fn (OnlineTool $tool) => $tool->value, OnlineTool::cases());

        $this->assertSame([], array_values(array_intersect($wholesale, $online)));

        foreach ([...WholesaleTool::cases(), ...OnlineTool::cases()] as $tool) {
            $this->assertSame($tool, EnsureShopUsesTool::tool($tool->value));
        }
    }

    #[DataProvider('categories')]
    public function test_the_courier_payouts_screens_are_only_found_for_online_trades(string $category, bool $online): void
    {
        $shop = Shop::create(['name' => 'Shop', 'business_category' => $category]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));

        $this->get(route('courier-settlements.index'))->assertStatus($online ? 200 : 404);
        $this->get(route('courier-settlements.create', ['courier' => 'leopards']))->assertStatus($online ? 200 : 404);

        $sidebar = $this->get(route('profile'));
        $online ? $sidebar->assertSee(route('courier-settlements.index')) : $sidebar->assertDontSee(route('courier-settlements.index'));
        // Shipments: an online seller keeps a parcel list without the plan's courier booking.
        $online ? $sidebar->assertSee(route('shipments.index')) : $sidebar->assertDontSee(route('shipments.index'));
    }

    public function test_a_staff_member_without_sales_gets_403_before_the_tool_check(): void
    {
        $shop = Shop::create(['name' => 'Shop', 'business_category' => 'social_seller']);
        $role = \App\Models\Role::create(['shop_id' => $shop->id, 'name' => 'Packer', 'permissions' => ['products']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id]));

        $this->get(route('courier-settlements.index'))->assertForbidden();
    }
}
