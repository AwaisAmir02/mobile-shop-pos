<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_totals_are_accurate_and_scoped_to_the_shop(): void
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

        $component = Livewire::test('reports.index')
            ->set('periodType', 'day')
            ->set('day', $today);

        $component
            ->assertSee('Rs 900.00')   // total sales revenue
            ->assertSee('Rs 150.00')  // total discount given (100 invoice + 50 item)
            ->assertSee('Rs 200.00')  // total balance loaded
            ->assertSee('Rs 300.00')  // total expenses
            ->assertSee('Rs 600.00')  // net = 900 - 300
            ->assertSee('Rs 750.00')  // mobile category revenue
            ->assertSee('Rs 250.00')  // accessory category revenue
            ->assertDontSee('Rs 5,000.00')
            ->assertDontSee('9,000.00');
    }
}
