<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Expense;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_accurate_totals_for_the_selected_day_scoped_to_the_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        $today = now()->toDateString();

        $saleA = Sale::create(['shop_id' => $shopA->id, 'user_id' => $userA->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);
        SaleItem::create(['shop_id' => $shopA->id, 'sale_id' => $saleA->id, 'product_name' => 'Test Mobile', 'product_type' => 'mobile', 'unit_price' => 1000, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 1000]);

        BalanceLoad::create(['shop_id' => $shopA->id, 'user_id' => $userA->id, 'network' => 'Jazz', 'amount' => 200]);
        WalletLoad::create(['shop_id' => $shopA->id, 'user_id' => $userA->id, 'provider' => 'JazzCash', 'account_number' => '03001234567', 'amount' => 300]);
        Expense::create(['shop_id' => $shopA->id, 'user_id' => $userA->id, 'description' => 'Rent', 'amount' => 400, 'expense_date' => $today]);

        // Shop B data must never leak into Shop A's dashboard.
        $saleB = Sale::create(['shop_id' => $shopB->id, 'user_id' => $userB->id, 'subtotal' => 9999, 'discount_amount' => 0, 'total' => 9999]);
        SaleItem::create(['shop_id' => $shopB->id, 'sale_id' => $saleB->id, 'product_name' => 'Other Shop Mobile', 'product_type' => 'mobile', 'unit_price' => 9999, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 9999]);

        $this->actingAs($userA);

        Livewire::test('dashboard.index')
            ->set('periodType', 'day')
            ->set('day', $today)
            ->assertSee('Rs 1,000.00')
            ->assertSee('Rs 200.00')
            ->assertSee('Rs 300.00')
            ->assertSee('Rs 400.00')
            ->assertSee('Rs 600.00')
            ->assertDontSee('Rs 9,999.00');
    }

    public function test_dashboard_supports_day_month_and_year_periods(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('dashboard.index')
            ->assertSet('periodType', 'day')
            ->call('setPeriodType', 'month')
            ->assertSet('periodType', 'month')
            ->call('setPeriodType', 'year')
            ->assertSet('periodType', 'year')
            ->assertHasNoErrors();
    }

    public function test_a_role_without_dashboard_access_is_redirected_away_from_the_root_route(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get('/')->assertRedirect(route('welcome'));
        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_the_owner_always_reaches_the_dashboard_from_root(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $this->get('/')->assertRedirect(route('dashboard'));
    }
}
