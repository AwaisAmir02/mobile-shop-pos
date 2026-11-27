<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\SaleReturns;
use App\Actions\UpdateSale;
use App\Enums\SaleLineKind;
use App\Models\SaleItem;
use App\ReportQueries\ProfitReport;
use App\Support\MoneyFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Services on the bill: non-stock lines beside products, with who did them. */
class ServiceLinesTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop();
    }

    public function test_one_bill_mixes_products_and_services_and_only_products_take_stock(): void
    {
        $sale = $this->bill([$this->serviceLine($this->haircut, $this->ali), $this->serviceLine($this->facial, $this->sara)], [[$this->shampoo, 2]], paid: 5700);

        $this->assertSame('5700.00', (string) $sale->total);
        $this->assertSame(0.0, $sale->fresh()->amountDue());
        $this->assertSame(8, (int) $this->shampoo->fresh()->stock_quantity);

        $services = SaleItem::where('sale_id', $sale->id)->where('line_kind', SaleLineKind::Service->value)->get();
        $this->assertCount(2, $services);
        $this->assertSame([$this->ali->id, $this->sara->id], $services->pluck('performed_by')->all());
        $this->assertNull($services->first()->product_id);
        $this->assertSame('0.00', (string) $services->first()->cost_price);
        $this->assertSame(SaleLineKind::Product, SaleItem::where('sale_id', $sale->id)->whereNotNull('product_id')->first()->line_kind);
    }

    public function test_profit_counts_services_at_no_cost(): void
    {
        $this->bill([$this->serviceLine($this->haircut)], [[$this->shampoo, 1]], paid: 2000);
        $totals = (new ProfitReport(now()->toDateString(), now()->toDateString()))->totals();

        $this->assertEqualsWithDelta(1300.0, $totals['grossProfit'] ?? $totals['profit'] ?? 0, 0.01);
    }

    public function test_an_unpaid_service_bill_is_a_due_like_any_sale(): void
    {
        $sale = $this->bill([$this->serviceLine($this->facial)], paid: 1000);

        $this->assertSame(1500.0, $sale->fresh()->amountDue());
        $this->assertSame(1500.0, \App\Support\CustomerDuesStatement::for($this->client)->total());
    }

    public function test_a_service_can_be_returned_for_money_without_restocking(): void
    {
        $sale = $this->bill([$this->serviceLine($this->haircut, $this->ali)], [[$this->shampoo, 1]], paid: 2000);
        $groups = collect(SaleReturns::groups($sale->fresh()));
        $this->assertTrue($groups->contains(fn ($g) => $g['product_id'] === null && $g['name'] === 'Haircut'));

        $key = $groups->first(fn ($g) => $g['name'] === 'Haircut')['key'];
        SaleReturns::create($sale->fresh(), [$key => ['quantity' => '1']], 'cash', null, null, $this->owner->id, now()->toDateString(), byOwner: true);

        $this->assertSame(9, (int) $this->shampoo->fresh()->stock_quantity);
        $this->assertSame(-800.0, collect((new MoneyFlow($this->shop->id))->movements())->where('source', 'refund')->sum('amount'));
    }

    public function test_memberships_are_not_returnable_and_a_service_bill_cant_be_edited(): void
    {
        $sale = $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id, 'quantity' => 1]], paid: 5000);
        $this->assertFalse(collect(SaleReturns::groups($sale->fresh()))->contains(fn ($g) => $g['name'] === 'Monthly'));

        $this->expectException(ValidationException::class);
        UpdateSale::handle($sale->fresh(), [], 0, $this->client->id, $this->owner->id, now()->toDateString());
    }

    public function test_cancelling_the_bill_leaves_stock_right_and_reports_no_skipped_products(): void
    {
        $sale = $this->bill([$this->serviceLine($this->haircut)], [[$this->shampoo, 1]], paid: 2000);
        $result = CancelSale::handle($sale, $this->owner->id, 'Mistake');

        $this->assertSame([], $result['skipped']);
        $this->assertSame(10, (int) $this->shampoo->fresh()->stock_quantity);
    }

    public function test_a_performer_from_another_shop_is_refused(): void
    {
        $other = \App\Models\Employee::withoutGlobalScopes()->create(['shop_id' => \App\Models\Shop::create(['name' => 'X'])->id, 'name' => 'Spy', 'rate' => 0]);

        $this->expectException(ValidationException::class);
        $this->bill([$this->serviceLine($this->haircut, $other)], paid: 800);
    }

    public function test_the_register_bills_services_with_a_performer(): void
    {
        Volt::test('sales.create')
            ->call('addService', $this->haircut->id)
            ->tap(function ($c) {
                $key = array_key_first($c->get('serviceLines'));
                $c->set("serviceLines.{$key}.performed_by", (string) $this->ali->id);
            })
            ->call('addToCart', $this->shampoo->id)
            ->assertSee('Rs 2,000.00')
            ->call('completeSale')
            ->assertHasNoErrors();

        $item = SaleItem::where('line_kind', 'service')->firstOrFail();
        $this->assertSame($this->ali->id, $item->performed_by);
        $this->assertSame(9, (int) $this->shampoo->fresh()->stock_quantity);
    }

    public function test_retail_registers_show_no_services_panel(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'K', 'business_category' => 'kiryana']);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));

        $this->get(route('sales.index'))->assertOk()->assertDontSee('data-services-panel', false);
    }
}
