<?php

namespace Tests\Feature;

use App\Actions\Appointments;
use App\Actions\CancelSale;
use App\Actions\CustomerDeposits;
use App\Actions\GiftVouchers;
use App\Support\CustomerDuesStatement;
use App\Support\MoneyFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Advance deposits and gift vouchers: money held for the customer, spent on a bill. */
class DepositsAndVouchersTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('salon');
    }

    protected function flow(string $source): float
    {
        return round(collect((new MoneyFlow($this->shop->id))->movements())->where('source', $source)->sum('amount'), 2);
    }

    public function test_a_deposit_is_cash_in_never_a_due_and_pays_the_bill(): void
    {
        $deposit = CustomerDeposits::receive($this->client, 1000, now()->toDateString(), 'cash', null, $this->owner->id);

        $this->assertSame(1000.0, $this->flow('prepaid'));
        $this->assertSame(0.0, CustomerDuesStatement::for($this->client)->total());

        $sale = $this->bill([$this->serviceLine($this->facial)], paid: 1500, prepaid: [['method' => 'deposit', 'id' => $deposit->id, 'amount' => 1000]]);
        $this->assertSame(0.0, $sale->fresh()->amountDue());
        $this->assertSame(0.0, $deposit->fresh()->remaining());
        $this->assertSame(1500.0, $this->flow('sale'), 'The deposit part of the bill moved no money');

        CancelSale::handle($sale, $this->owner->id, 'x');
        $this->assertSame(1000.0, $deposit->fresh()->remaining());
    }

    public function test_a_deposit_only_pays_its_own_customers_bill(): void
    {
        $deposit = CustomerDeposits::receive($this->client, 1000, now()->toDateString(), 'cash', null, $this->owner->id);
        $other = \App\Models\Customer::create(['name' => 'Other', 'phone' => '03009999999']);

        $this->expectException(ValidationException::class);
        $this->bill([$this->serviceLine($this->facial)], customer: $other, paid: 1500, prepaid: [['method' => 'deposit', 'id' => $deposit->id, 'amount' => 1000]]);
    }

    public function test_refund_and_forfeit(): void
    {
        $a = CustomerDeposits::receive($this->client, 1000, now()->toDateString(), 'cash', null, $this->owner->id);
        $b = CustomerDeposits::receive($this->client, 500, now()->toDateString(), 'cash', null, $this->owner->id);
        CustomerDeposits::refund($a, 600, now()->toDateString(), 'cash', null);
        CustomerDeposits::forfeit($b, now()->toDateString());

        $this->assertSame(400.0, $a->fresh()->remaining());
        $this->assertSame(0.0, $b->fresh()->remaining());
        $this->assertSame(900.0, $this->flow('prepaid'), '1,500 in, 600 back; what was kept stays in the till');
    }

    public function test_a_booking_deposit_is_used_when_the_booking_is_billed(): void
    {
        $appointment = Appointments::book(['customer_id' => $this->client->id, 'employee_id' => $this->ali->id, 'date' => '2026-11-10', 'time' => '15:00'], [['service_item_id' => $this->facial->id]], $this->shop->id, null);
        $deposit = CustomerDeposits::receive($this->client, 1000, now()->toDateString(), 'cash', null, null, appointment: $appointment);

        \Livewire\Livewire::withQueryParams(['appointment' => $appointment->id])->test('sales.create')
            ->assertSee('Use deposit')
            ->call('completeSale')->assertHasNoErrors();

        $this->assertSame(0.0, $deposit->fresh()->remaining());
        $this->assertSame(1500.0, $this->flow('sale'));
    }

    public function test_a_voucher_is_sold_then_spent_by_code_across_bills(): void
    {
        $voucher = GiftVouchers::sell(['amount' => 3000, 'recipient_name' => 'Ayesha'], $this->owner->id, $this->shop->id);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $voucher->code);
        $this->assertSame(3000.0, $this->flow('prepaid'));

        Volt::test('sales.create')
            ->call('addService', $this->facial->id)
            ->set('voucherCode', strtolower($voucher->code))
            ->call('applyVoucher')->assertHasNoErrors()
            ->call('completeSale')->assertHasNoErrors();

        $this->assertSame(500.0, $voucher->fresh()->remaining());
        $this->assertSame(0.0, $this->flow('sale'));

        $this->assertSame('That voucher has been used up.', GiftVouchers::problem(tap($voucher->fresh(), fn () => $this->bill([$this->serviceLine($this->haircut)], prepaid: [['method' => 'gift_voucher', 'id' => $voucher->id, 'amount' => 500]], paid: 300))->fresh(), now()->toDateString()));
    }

    public function test_expired_and_cancelled_vouchers_are_refused(): void
    {
        $voucher = GiftVouchers::sell(['amount' => 1000, 'expires_on' => '2026-11-20'], $this->owner->id, $this->shop->id);
        $this->assertStringContainsString('ran out', GiftVouchers::problem($voucher, '2026-11-21'));

        GiftVouchers::cancel($voucher);
        $this->assertSame('That voucher was cancelled.', GiftVouchers::problem($voucher->fresh(), '2026-11-11'));
        $this->assertSame(0.0, $this->flow('prepaid'));
    }

    public function test_the_vouchers_screen_sells_one(): void
    {
        Volt::test('gift-vouchers.index')->set('amount', '2000')->set('recipientName', 'Ayesha')->call('sell')->assertHasNoErrors();
        $this->get(route('gift-vouchers.index'))->assertOk()->assertSee('Ayesha')->assertSee('Rs 2,000');
    }
}
