<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CourierSettlements;
use App\Actions\CustomerPayments;
use App\Actions\StartLedger;
use App\Enums\CourierKey;
use App\Enums\RecordStatus;
use App\Enums\ShipmentStatus;
use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerPoster;
use App\Models\CourierSettlement;
use App\Models\LedgerAccount;
use App\Models\Sale;
use App\Models\Shipment;
use App\ReportQueries\CourierBalances;
use App\ReportQueries\DuesAging;
use App\ReportQueries\LedgerHealthReport;
use App\ReportQueries\SalesReport;
use App\ReportQueries\UdhaarReport;
use App\Support\CustomerDuesStatement;
use App\Support\MoneyFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O4: cash on delivery is owed by the courier, not the customer, until the
 * courier pays it out; one payout settles many parcels, net of its charges and
 * the tax it withheld. Shops that don't track COD keep their dues as before.
 */
class CourierSettlementsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop();
    }

    /** @param  list<array{0: Shipment, 1: float, 2?: float, 3?: float}>  $lines  [parcel, collected, delivery charge, return charge] */
    protected function payout(array $lines, float $tax = 0, float $other = 0, string $method = 'account'): CourierSettlement
    {
        return CourierSettlements::record($this->shop, CourierKey::Leopards, array_map(fn (array $line) => [
            'shipment_id' => $line[0]->id, 'cod_collected' => $line[1], 'courier_charge' => $line[2] ?? 0, 'return_charge' => $line[3] ?? 0,
        ], $lines), ['settlement_date' => now()->toDateString(), 'reference' => 'CPR-77', 'tax_withheld' => $tax, 'other_deductions' => $other,
            'payment_method' => $method, 'shop_account_id' => $method === 'account' ? $this->bank->id : null], $this->owner->id);
    }

    protected function customerOwes(Sale $sale): float
    {
        return round(CustomerDuesStatement::for($this->customerOf($sale))->total(), 2);
    }

    public function test_a_parcel_with_the_courier_is_in_no_customers_dues_until_paid_out(): void
    {
        $sale = $this->codSale();
        $customer = $this->customerOf($sale);
        $this->assertSame(3750.0, $this->customerOwes($sale));

        $this->parcel($sale, ShipmentStatus::InTransit);

        $this->assertSame(0.0, $this->customerOwes($sale));
        $this->assertSame(0.0, (new UdhaarReport)->totalDue());
        $this->assertSame([], (new UdhaarReport)->dueRecords($customer->id));
        $this->assertSame(0.0, $customer->fresh()->salesOutstandingBalance());
        $this->assertSame(0.0, DuesAging::today()->forCustomer($customer)['bills']['total']);
        // A payment at the counter can't land on it either: it's all kept as an advance.
        $this->assertSame(['advance'], array_column(CustomerPayments::allocate($customer, 1000), 'source'));

        // The shop is still owed it: the sale is unpaid, with the courier, and outstanding on Sales.
        $this->assertSame(3750.0, $sale->fresh()->amountDue());
        $this->assertSame(3750.0, (new SalesReport)->totalOutstanding());
        $this->get(route('sales.show', $sale))->assertSee('With Leopards Courier');
        $this->assertFalse((new UdhaarReport)->rows()->contains(fn (array $row) => $row['customer']->id === $customer->id));
        $this->assertSame(3750.0, (new CourierBalances)->rows()->sole()['on_the_way']);
    }

    public function test_a_payout_settles_its_parcels_net_of_charges_and_tax_in_one_movement_of_money(): void
    {
        $first = $this->codSale(1, 250, '0321 4445556');
        $second = $this->codSale(1, 250, '0333 1112223');
        $one = $this->parcel($first);
        $two = $this->parcel($second);
        $moneyBefore = $this->netMoneyMoved();

        $settlement = $this->payout([[$one, 3750, 200], [$two, 3750, 200]], tax: 150, other: 36);

        $this->assertSame(['7500.00', '400.00', '150.00', '36.00', '6914.00'], [$settlement->cod_total, $settlement->charges_total, $settlement->tax_withheld, $settlement->other_deductions, $settlement->net_amount]);
        $this->assertSame([0.0, 0.0], [$first->fresh()->amountDue(), $second->fresh()->amountDue()]);
        $this->assertSame('Leopards Courier payout CS-000001', $first->fresh()->payments()->latest('id')->first()->note);
        $this->assertSame([$settlement->id, $settlement->id], [$one->fresh()->courier_settlement_id, $two->fresh()->courier_settlement_id]);

        // Only the net reached the bank, once, as a sales receipt; the per-parcel payments move no money.
        $flow = (new MoneyFlow($this->shop->id))->movements()->where('label', 'Courier payout CS-000001');
        $this->assertSame([['account:'.$this->bank->id, 6914.0]], $flow->map(fn ($row) => [$row['bucket'], $row['amount']])->values()->all());
        $this->assertSame(6914.0, round($this->netMoneyMoved() - $moneyBefore, 2));
        $this->assertSame(0.0, (new SalesReport)->totalOutstanding());
        $this->assertTrue((new CourierBalances)->rows()->isEmpty());
    }

    public function test_cash_short_of_the_bill_is_then_owed_by_the_customer_and_a_cancelled_payout_makes_the_courier_owe_again(): void
    {
        $sale = $this->codSale(1, 250);
        $parcel = $this->parcel($sale);

        $settlement = $this->payout([[$parcel, 3500]]);
        $this->assertSame(250.0, $this->customerOwes($sale));

        CourierSettlements::cancel($settlement, $this->owner->id);

        $this->assertSame(RecordStatus::Cancelled, $settlement->fresh()->status);
        $this->assertNull($parcel->fresh()->courier_settlement_id);
        $this->assertSame(3750.0, $sale->fresh()->amountDue());
        $this->assertSame(0.0, $this->customerOwes($sale));
        $this->assertSame(0.0, round((float) (new MoneyFlow($this->shop->id))->movements()->where('label', 'Courier payout CS-000001')->sum('amount'), 2));
    }

    public function test_a_payout_refuses_what_doesnt_add_up(): void
    {
        $sale = $this->codSale(1, 250);
        $delivered = $this->parcel($sale);
        $back = $this->parcel($this->codSale(1, 250, '0300 1231231'), ShipmentStatus::Returned);
        $onTheWay = $this->parcel($this->codSale(1, 250, '0300 4564564'), ShipmentStatus::InTransit);

        $refused = function (callable $record, string $message) {
            try {
                $record();
                $this->fail("Should be refused: {$message}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, collect($e->errors())->flatten()->implode(' '));
            }
        };

        $refused(fn () => $this->payout([[$delivered, 3751]]), 'more than the Rs 3,750.00 still owed');
        $refused(fn () => $this->payout([[$back, 100]]), 'came back, so no cash was collected');
        $refused(fn () => $this->payout([[$onTheWay, 3750]]), "hasn't been delivered or returned yet");
        $refused(fn () => $this->payout([[$delivered, 300, 400]]), 'are more than the cash it collected');
        $refused(fn () => $this->payout([[$delivered, 3750]], method: ''), 'Choose where the money was paid');

        $this->payout([[$delivered, 3750]]);
        $refused(fn () => $this->payout([[$delivered, 3750]]), 'is already on a payout');
        $refused(fn () => CancelSale::handle($sale->fresh(), $this->owner->id, 'oops'), 'A courier payout paid this sale');
        $this->assertSame(1, CourierSettlement::count());
    }

    public function test_the_payout_screen_lists_whats_waiting_and_records_it(): void
    {
        $delivered = $this->parcel($this->codSale(1, 250));
        $back = $this->parcel($this->codSale(1, 250, '0300 1231231'), ShipmentStatus::Returned);
        $back->update(['return_charge' => 120]);

        $this->get(route('courier-settlements.index'))->assertOk()->assertSee('Leopards Courier')->assertSee('Rs 3,750.00');

        Livewire::test('courier-settlements.create', ['courier' => 'leopards'])
            ->assertSet("lines.{$delivered->id}.cod_collected", '3750')
            ->assertSet("lines.{$back->id}.return_charge", '120')
            ->set("lines.{$delivered->id}.courier_charge", '200')
            ->set('taxWithheld', '75')
            ->set('paymentMethod', 'cash')
            ->assertSee('Rs 3,355.00')
            ->call('save')
            ->assertHasNoErrors();

        $settlement = CourierSettlement::sole();
        $this->assertSame(['3750.00', '320.00', '75.00', '3355.00', 'cash'], [$settlement->cod_total, $settlement->charges_total, $settlement->tax_withheld, $settlement->net_amount, $settlement->payment_method]);
        $this->get(route('courier-settlements.show', $settlement))->assertOk()->assertSee($delivered->tracking_number);
    }

    public function test_a_retail_shops_cod_parcel_stays_in_its_customers_dues_as_before(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'Corner', 'business_category' => 'clothing', 'couriers_enabled' => true]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $product = \App\Models\Product::create(['shop_id' => $retail->id, 'type' => 'accessory', 'name' => 'Shirt', 'price' => 1000, 'stock_quantity' => 5, 'details' => []]);
        $customer = \App\Models\Customer::create(['shop_id' => $retail->id, 'name' => 'Sana', 'phone' => '03001112223']);
        $sale = \App\Actions\CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, $customer->id, auth()->id(), now()->toDateString(), 0);
        $shipment = \App\Actions\Shipments::recordManual($sale, CourierKey::Leopards, null, 'LE1', [], auth()->id());

        $this->assertFalse($shipment->cod_tracked);
        $this->assertSame(1000.0, CustomerDuesStatement::for($customer)->total());
        $this->get(route('courier-settlements.index'))->assertNotFound();
    }

    public function test_the_ledger_balances_and_agrees_with_the_records_through_delivery_charges_and_payouts(): void
    {
        // Already started under LEDGER_FORCE_ON.
        $this->shop = $this->shop->fresh()->ledger_started_at ? $this->shop->fresh() : StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        $this->actingAs($this->owner->fresh());

        $one = $this->parcel($this->codSale(1, 250));
        $two = $this->parcel($this->codSale(1, 250, '0333 1112223'));
        $this->payout([[$one, 3750, 200], [$two, 3750, 200]], tax: 150);
        CourierSettlements::cancel($this->payout([[$this->parcel($this->codSale(1, 0, '0300 9998887')), 3500]]), $this->owner->id);

        app(LedgerPoster::class)->drain(limit: 100000);
        $this->assertNull(\App\Models\LedgerOutbox::whereNotNull('last_error')->value('last_error'));
        $this->assertSame(0, (int) \App\Models\LedgerLine::sum('amount_paise'));

        $balance = fn (string $code) => (int) \App\Models\LedgerLine::whereIn('ledger_account_id', LedgerAccount::where('shop_id', $this->shop->id)->where('code', $code)->pluck('id'))->sum('amount_paise');
        $this->assertSame(-50000, $balance(ChartOfAccounts::DELIVERY_INCOME));
        $this->assertSame(40000, $balance(ChartOfAccounts::COURIER_CHARGES));
        $this->assertSame(15000, $balance(ChartOfAccounts::TAX_DEDUCTED));
        $this->assertSame(0, $balance(ChartOfAccounts::COURIER_CLEARING));
        $this->assertSame(695000, $balance(ChartOfAccounts::bankCode($this->bank->id)));

        foreach ((new LedgerHealthReport($this->shop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'] ?? 0.0, abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }
}
