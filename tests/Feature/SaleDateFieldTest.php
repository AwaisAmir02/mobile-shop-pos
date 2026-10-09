<?php

namespace Tests\Feature;

use App\Actions\UpdateSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRevision;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\SalesReport;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleDateFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwnerWithProduct(int $stock = 10, float $price = 500): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => $price, 'stock_quantity' => $stock, 'details' => ['category' => 'Cable']]);

        return [$shop, $owner, $product];
    }

    public function test_the_date_field_defaults_to_today(): void
    {
        $this->shopOwnerWithProduct();

        Livewire::test('sales.create')->assertSet('saleDate', now()->toDateString());
    }

    public function test_a_sale_can_be_backdated_and_its_created_at_reflects_the_chosen_date(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', '2026-01-15')
            ->call('completeSale');

        $sale = Sale::first();
        $this->assertSame('2026-01-15', $sale->created_at->toDateString());
    }

    public function test_a_backdated_sale_appears_in_history_for_that_date_not_today(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', '2026-01-15')
            ->call('completeSale');

        $onDate = (new SalesReport('2026-01-15', '2026-01-15'))->query()->count();
        $today = (new SalesReport(now()->toDateString(), now()->toDateString()))->query()->count();

        $this->assertSame(1, $onDate);
        $this->assertSame(0, $today);
    }

    public function test_a_backdated_sale_counts_toward_that_dates_revenue_not_todays(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(price: 500);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', '2026-01-15')
            ->call('completeSale');

        $reports = app(ShopReportService::class);
        $onDate = $reports->summary($shop->fresh(), \Carbon\Carbon::parse('2026-01-15')->startOfDay(), \Carbon\Carbon::parse('2026-01-15')->endOfDay());
        $today = $reports->summary($shop->fresh(), now()->startOfDay(), now()->endOfDay());

        $this->assertSame(500.0, $onDate['totalRevenue']);
        $this->assertSame(0.0, $today['totalRevenue']);
    }

    public function test_the_revenue_trend_chart_buckets_a_backdated_sale_under_its_own_date(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(price: 500);
        $backdated = now()->subDays(3)->toDateString();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', $backdated)
            ->call('completeSale');

        $trend = app(ShopReportService::class)->revenueTrend($shop->fresh(), 'day');
        $bucket = collect($trend)->firstWhere('label', now()->subDays(3)->format('d M'));

        $this->assertNotNull($bucket);
        $this->assertSame(500.0, $bucket['value']);
    }

    public function test_a_future_date_is_rejected_on_create(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', now()->addDay()->toDateString())
            ->call('completeSale')
            ->assertHasErrors(['saleDate']);

        $this->assertSame(0, Sale::count());
    }

    public function test_an_invalid_date_is_rejected_on_create(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', 'not-a-date')
            ->call('completeSale')
            ->assertHasErrors(['saleDate']);

        $this->assertSame(0, Sale::count());
    }

    public function test_editing_a_sales_date_moves_it_in_every_report_it_is_grouped_by(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(price: 500);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('saleDate', '2026-01-15')
            ->call('completeSale');

        $sale = Sale::first();

        UpdateSale::handle(
            $sale,
            [['product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'quantity' => 1, 'discount' => 0, 'custom_price' => 500]],
            0.0,
            null,
            $owner->id,
            '2026-02-20',
        );

        $this->assertSame('2026-02-20', $sale->fresh()->created_at->toDateString());

        $oldDateCount = (new SalesReport('2026-01-15', '2026-01-15'))->query()->count();
        $newDateCount = (new SalesReport('2026-02-20', '2026-02-20'))->query()->count();

        $this->assertSame(0, $oldDateCount);
        $this->assertSame(1, $newDateCount);
    }

    public function test_the_edit_screen_pre_fills_the_sales_current_date(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        $sale->created_at = \Carbon\Carbon::parse('2026-03-10 14:00:00');
        $sale->save();
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        Livewire::test('sales.edit', ['sale' => $sale])->assertSet('saleDate', '2026-03-10');
    }

    public function test_a_future_date_is_rejected_on_edit(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        Livewire::test('sales.edit', ['sale' => $sale])
            ->set('saleDate', now()->addDay()->toDateString())
            ->call('saveChanges')
            ->assertHasErrors(['saleDate']);
    }

    public function test_editing_only_other_fields_keeps_the_original_date(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(stock: 10);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        $sale->created_at = \Carbon\Carbon::parse('2026-03-10 09:00:00');
        $sale->save();
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 500, 'payment_date' => now()->toDateString()]);

        Livewire::test('sales.edit', ['sale' => $sale])
            ->call('saveChanges')
            ->assertHasNoErrors();

        $this->assertSame('2026-03-10', $sale->fresh()->created_at->toDateString());
    }

    public function test_updated_at_shifting_on_edit_does_not_affect_history_ordering_which_uses_created_at(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(price: 500);
        $older = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        $older->created_at = now()->subDays(5);
        $older->save();
        $newer = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);
        $newer->created_at = now()->subDay();
        $newer->save();

        $older->touch();

        $ordered = (new SalesReport)->query()->latest()->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ordered);
    }

    public function test_changing_the_date_on_edit_is_captured_in_the_sale_revision_snapshot(): void
    {
        [$shop, $owner, $product] = $this->shopOwnerWithProduct(price: 500);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        $sale->created_at = \Carbon\Carbon::parse('2026-01-01 10:00:00');
        $sale->save();
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 500, 'payment_date' => now()->toDateString()]);

        UpdateSale::handle(
            $sale,
            [['product_id' => $product->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'quantity' => 1, 'discount' => 0, 'custom_price' => 500]],
            0.0,
            null,
            $owner->id,
            '2026-05-05',
        );

        $revision = SaleRevision::where('sale_id', $sale->id)->first();
        $this->assertNotNull($revision);
        $this->assertStringStartsWith('2026-01-01T10:00:00', $revision->snapshot['created_at']);
    }

    public function test_sale_date_tenant_isolation_is_unaffected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);

        $saleA = Sale::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        $saleA->created_at = \Carbon\Carbon::parse('2026-01-15');
        $saleA->save();

        $saleB = Sale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'subtotal' => 999, 'discount_amount' => 0, 'total' => 999]);
        $saleB->created_at = \Carbon\Carbon::parse('2026-01-15');
        $saleB->save();

        $this->actingAs($ownerA);

        $onDate = (new SalesReport('2026-01-15', '2026-01-15'))->query()->get();
        $this->assertCount(1, $onDate);
        $this->assertSame($saleA->id, $onDate->first()->id);
    }
}
