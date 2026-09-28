<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The old Reports screen (a single day/month cross-module revenue
 * dashboard) was reworked into a per-screen picker — see
 * App\ReportQueries\* and ReportsScreenTest for its new behavior. Every
 * figure these tests originally checked is still computed by the same,
 * unchanged ShopReportService::summary() — the top-level totals still
 * render as visible text on the Dashboard (the surviving screen backed by
 * that service), while the per-category revenue breakdown was never
 * re-rendered as text anywhere and is checked directly against the
 * service instead.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_dashboard_totals_are_accurate_and_scoped_to_the_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        $today = now()->toDateString();

        $saleA = Sale::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'subtotal' => 1000,
            'discount_amount' => 100,
            'total' => 900,
        ]);

        SaleItem::create([
            'shop_id' => $shopA->id,
            'sale_id' => $saleA->id,
            'product_name' => 'Test Mobile',
            'product_type' => 'mobile',
            'unit_price' => 800,
            'quantity' => 1,
            'discount_amount' => 50,
            'line_total' => 750,
        ]);

        SaleItem::create([
            'shop_id' => $shopA->id,
            'sale_id' => $saleA->id,
            'product_name' => 'Test Cable',
            'product_type' => 'accessory',
            'unit_price' => 250,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 250,
        ]);

        BalanceLoad::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'network' => 'Jazz',
            'amount' => 200,
        ]);

        Expense::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'description' => 'Electricity',
            'amount' => 300,
            'expense_date' => $today,
        ]);

        // Shop B data — must never affect shop A's report.
        $saleB = Sale::create([
            'shop_id' => $shopB->id,
            'user_id' => $userB->id,
            'subtotal' => 5000,
            'discount_amount' => 0,
            'total' => 5000,
        ]);

        SaleItem::create([
            'shop_id' => $shopB->id,
            'sale_id' => $saleB->id,
            'product_name' => 'Shop B Mobile',
            'product_type' => 'mobile',
            'unit_price' => 5000,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 5000,
        ]);

        Expense::create([
            'shop_id' => $shopB->id,
            'description' => 'Shop B rent',
            'amount' => 9000,
            'expense_date' => $today,
        ]);

        $this->actingAs($userA);

        $component = Livewire::test('dashboard.index')
            ->set('periodType', 'day')
            ->set('day', $today);

        $component
            ->assertSee('Rs 900.00')   // total sales revenue
            ->assertSee('Rs 200.00')  // total balance loaded
            ->assertSee('Rs 300.00')  // total expenses
            ->assertSee('Rs 600.00')  // net = 900 - 300
            ->assertDontSee('Rs 5,000.00')
            ->assertDontSee('9,000.00');

        // The per-category revenue breakdown isn't rendered as visible text
        // anywhere post-rework, so it's checked directly against the
        // service that still computes it.
        $summary = app(ShopReportService::class)->summary($shopA, now()->startOfDay(), now()->endOfDay());

        $this->assertSame(150.0, $summary['totalDiscount']); // 100 invoice + 50 item
        $this->assertSame(750.0, collect($summary['categories'])->firstWhere('slug', 'mobile')['revenue']);
        $this->assertSame(250.0, collect($summary['categories'])->firstWhere('slug', 'accessory')['revenue']);
    }

    public function test_balance_load_fees_appear_as_their_own_revenue_line_separate_from_amount_loaded(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        BalanceLoad::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'network' => 'Jazz',
            'amount' => 1000,
            'fee' => 50,
            'discount' => 10,
            'total' => 1040,
        ]);

        $this->actingAs($owner);

        Livewire::test('dashboard.index')
            ->set('day', $today)
            ->assertSee('Rs 1,000.00') // amount loaded (volume)
            ->assertSee('Rs 40.00');   // fee (50) minus discount (10) = net service revenue
    }
}
