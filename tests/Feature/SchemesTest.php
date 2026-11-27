<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\Schemes;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scheme;
use App\Models\SchemeRedemption;
use App\Models\StockIn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Schemes (W9): free pieces at the register, bonus quantity on Stock In, and
 * claims on the company settled as credit or money.
 */
class SchemesTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_a_scheme_gives_free_pieces_at_the_register_and_records_them_for_the_claim(): void
    {
        $this->stock(100, 1200);
        Scheme::create(['shop_id' => $this->shop->id, 'name' => '10+1', 'product_id' => $this->tea->id, 'vendor_id' => $this->vendor->id, 'buy_quantity' => 10, 'free_quantity' => 1, 'starts_on' => '2026-11-01']);
        $customer = $this->customer();

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 20);
        $register->assertSee('Scheme 10 + 1 free: +2 free');
        $register->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('28000.00', $sale->total);   // 20 x trade 1,400; the 2 free cost nothing
        $this->assertSame(78, $this->tea->fresh()->stock_quantity);
        $this->assertSame(22, (int) SaleItem::where('sale_id', $sale->id)->sum('quantity'));
        $redemption = SchemeRedemption::sole();
        $this->assertSame(['2', '2400.00'], [(string) $redemption->free_quantity, $redemption->cost_value]);

        // A scheme sale can't be edited (its claim is per sale): no Edit button, and UpdateSale refuses.
        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee(route('sales.edit', $sale));
        $this->expectException(ValidationException::class);
        \App\Actions\UpdateSale::handle($sale, [], 0, $customer->id, $this->owner->id, '2026-11-20');
    }

    public function test_bonus_stock_spreads_the_cost_and_owes_only_what_was_bought(): void
    {
        Livewire::test('stock-ins.create')
            ->set('productId', (string) $this->tea->id)->set('quantity', '10')->set('costPrice', '1200')->set('sellingPrice', '1500')->set('freeQuantity', '2')
            ->call('addLine')->set('vendorId', (string) $this->vendor->id)->set('paymentStatus', 'unpaid')->call('save')->assertHasNoErrors();

        $batch = StockIn::sole();
        $this->assertSame([12, '12000.00', '1000.00', '2.000'], [$batch->quantity, $batch->total_cost, $batch->cost_price, (string) $batch->getRawOriginal('free_quantity')]);
        $this->assertSame(12000.0, $this->vendor->totalOwed());
        $this->assertSame(12, $this->tea->fresh()->stock_quantity);
    }

    public function test_free_goods_are_claimed_from_the_company_and_settle_as_credit_or_money(): void
    {
        $this->stock(200, 1200, 'unpaid', '2026-11-01');
        $scheme = Scheme::create(['shop_id' => $this->shop->id, 'name' => '10+1', 'product_id' => $this->tea->id, 'vendor_id' => $this->vendor->id, 'buy_quantity' => 10, 'free_quantity' => 1, 'starts_on' => '2026-11-01']);
        $customer = $this->customer();
        $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        $this->sell($customer, 20, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 2]);
        $cancelled = $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        CancelSale::handle($cancelled->fresh(), $this->owner->id, 'x');

        $claim = Schemes::claim($this->vendor->id, null, null, 'November', $this->owner->id);
        $this->assertSame('3600.00', $claim->claimed_amount);   // 3 free x 1,200, the cancelled sale left out

        $html = view('pdf.scheme-claim', ['claim' => $claim->fresh(['vendor', 'redemptions.scheme', 'redemptions.sale.customer']), 'shop' => $this->shop])->render();
        $this->assertStringContainsString($claim->number(), $html);

        $owed = $this->vendor->totalOwed();
        Schemes::settle($claim, 3000, 'credit', null, null, $this->owner->id);   // short by 600
        $this->assertSame(round($owed - 3000, 2), $this->vendor->fresh()->totalOwed());
        $this->assertSame(0.0, $this->netMoneyMoved());

        $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        $second = Schemes::claim($this->vendor->id, null, null, null, $this->owner->id);
        Schemes::settle($second, 1200, 'cash', 'account', $this->bank->id, $this->owner->id);
        $this->assertSame(1200.0, $this->netMoneyMoved());

        Livewire::test('schemes.index', ['tab' => 'claims'])->assertSee($claim->number())->assertSee('Short paid');
    }
}
