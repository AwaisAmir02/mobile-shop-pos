<?php

namespace Tests\Feature;

use App\Actions\MoneyEntries;
use App\Enums\WalletLoadDirection;
use App\Ledger\Builders\WalletLoadBuilder;
use App\Ledger\ChartOfAccounts as Chart;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Branch;
use App\Models\CounterServicePayment;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\MoneyEntry;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use App\ReportQueries\UdhaarReport;
use App\Support\MoneyFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cash & Balances, and the corrected Wallet Load rules: the shop always pays
 * out in full, so any shortfall on Cash In or Cash Out is the customer's due.
 */
class MoneyFlowTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private ShopAccount $jazz;

    private ShopAccount $bank;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 UTC is 15:00 in Pakistan.
        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->shop = Shop::create(['name' => 'Ali Mobiles']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);
        $this->actingAs($this->owner);
        $this->jazz = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'JazzCash', 'provider_type' => 'JazzCash']);
        $this->bank = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        $this->branchId = \App\Support\BranchContext::current()->mainBranchIdFor($this->shop->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flow(): MoneyFlow
    {
        return new MoneyFlow($this->shop->id);
    }

    private function cash(): string
    {
        return 'cash:'.$this->branchId;
    }

    private function balance(string $bucket): float
    {
        return (float) $this->flow()->balances()->get($bucket)['balance'];
    }

    private function wallet(string $direction, array $overrides = []): WalletLoad
    {
        return WalletLoad::create(array_merge([
            'shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'direction' => $direction, 'provider' => 'JazzCash',
            'shop_account_id' => $this->jazz->id, 'amount' => 1000, 'fee' => 20, 'discount' => 0, 'fee_included_in_amount' => false,
            'net_amount' => 1000, 'total' => 1020, 'payment_status' => 'paid', 'amount_paid' => 1020,
        ], $overrides));
    }

    public function test_cash_in_moves_cash_up_and_the_account_down(): void
    {
        $this->wallet('cash_in');

        $this->assertSame(1020.0, $this->balance($this->cash()));
        $this->assertSame(-1000.0, $this->balance('account:'.$this->jazz->id));
    }

    public function test_cash_out_moves_the_account_up_and_cash_down(): void
    {
        $this->wallet('cash_out');

        $this->assertSame(-1000.0, $this->balance($this->cash()));
        $this->assertSame(1020.0, $this->balance('account:'.$this->jazz->id));
    }

    public function test_the_wallet_form_records_where_the_customer_paid_without_asking(): void
    {
        Livewire::test('wallet-loads.create')
            ->call('switchDirection', 'cash_out')
            ->set('shopAccountId', (string) $this->jazz->id)
            ->set('amount', '2000')
            ->set('fee', '50')
            ->set('amountReceivedNow', '1500')
            ->assertSee('Customer Sends To (your account)')
            ->assertSee('Received in Your Account')
            ->assertSee('Still Owed by Customer')
            ->assertDontSee('Still Owed to Customer')
            ->assertDontSeeHtml('id="paidFrom"')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::sole();
        $payment = CounterServicePayment::sole();
        $this->assertSame(['account', $this->jazz->id], [$payment->payment_method, $payment->shop_account_id]);
        $this->assertSame(1500.0, (float) $payment->amount);
        $this->assertSame(550.0, $load->amountOwed());

        // Cash handed over in full; the customer's part so far reached the account.
        $this->assertSame(-2000.0, $this->balance($this->cash()));
        $this->assertSame(1500.0, $this->balance('account:'.$this->jazz->id));
    }

    public function test_a_cash_out_shortfall_is_the_customers_due(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal']);
        $this->wallet('cash_out', ['customer_id' => $customer->id, 'payment_status' => 'partial', 'amount_paid' => 820]);

        $this->assertSame(200.0, (float) (new UdhaarReport)->rows()->firstWhere('customer.id', $customer->id)['balance']);

        Livewire::test('wallet-loads.history')
            ->call('switchTab', 'cash_out')
            ->assertSee('Total Owed by Customers')
            ->assertDontSee('Total Owed to Customers')
            ->assertViewHas('totalOwed', 200.0);
    }

    public function test_older_cash_out_rows_marked_by_the_migration_stay_out_of_dues(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal']);
        $load = $this->wallet('cash_out', ['customer_id' => $customer->id, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'excluded_from_dues' => true]);

        $this->assertFalse($load->countsAsDue());
        $this->assertNull((new UdhaarReport)->rows()->firstWhere('customer.id', $customer->id));
        Livewire::test('wallet-loads.history')->call('switchTab', 'cash_out')->assertViewHas('totalOwed', 0.0)->assertSee('Not in dues (older entry)');

        // The ledger keeps its old, settled posting for those rows.
        $lines = collect(app(WalletLoadBuilder::class)->lines($load->fresh()));
        $this->assertSame(102000, $lines->where('account', Chart::CLEARING)->sum('paise'));
        $this->assertNull($lines->firstWhere('account', Chart::RECEIVABLE));
    }

    public function test_the_ledger_treats_a_cash_out_shortfall_as_receivable_and_balances(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal']);
        $load = $this->wallet('cash_out', ['customer_id' => $customer->id, 'payment_status' => 'partial', 'amount_paid' => 0]);
        \App\Actions\RecordCounterPayment::handle($load, 800, null, null, $this->owner->id);
        $load->update(['amount_paid' => 800]);

        // Paise are signed: debit positive, credit negative.
        $lines = collect(app(WalletLoadBuilder::class)->lines($load->fresh()));
        $net = fn (string $account) => $lines->where('account', $account)->sum('paise');
        $this->assertSame(0, $lines->sum('paise'));
        $this->assertSame(22000, $net(Chart::RECEIVABLE));
        $this->assertSame(80000, $net(Chart::CLEARING));
        $this->assertSame(-100000, $net(Chart::CASH));
    }

    public function test_every_shop_now_records_where_counter_service_money_went(): void
    {
        // LEDGER_FORCE_ON starts the ledger on every shop (tests/TestCase.php), so a shop without it can't be tested then.
        if (env('LEDGER_FORCE_ON')) {
            $this->markTestSkipped('Needs a shop with the ledger off.');
        }
        $this->assertFalse((bool) $this->shop->ledger_enabled);
        $category = BillCategory::create(['shop_id' => $this->shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $this->shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);

        Livewire::test('bills.create')
            ->assertSeeHtml('id="paidFrom"')
            ->assertSet('paidFrom', 'cash')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('consumerNumber', '123')
            ->set('consumerName', 'Ali')
            ->set('amount', '3000')
            ->set('shopAccountId', (string) $this->bank->id)
            ->set('fee', '30')
            ->set('paymentStatus', 'paid')
            ->set('paidFrom', (string) $this->jazz->id)
            ->call('save')
            ->assertHasNoErrors();

        // The bill left the bank; the customer's 3030 went into JazzCash.
        $this->assertSame(-3000.0, $this->balance('account:'.$this->bank->id));
        $this->assertSame(3030.0, $this->balance('account:'.$this->jazz->id));
        $this->assertSame(30.0, $this->flow()->feesByDay('2026-11-10', '2026-11-10')['2026-11-10']);
    }

    public function test_sales_udhaar_vendors_expenses_and_payroll_all_count_once(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal']);
        $sale = Sale::create(['shop_id' => $this->shop->id, 'subtotal' => 500, 'total' => 500]);
        SalePayment::create(['shop_id' => $this->shop->id, 'sale_id' => $sale->id, 'amount' => 300, 'payment_date' => '2026-11-10', 'payment_method' => 'cash']);
        SalePayment::create(['shop_id' => $this->shop->id, 'sale_id' => $sale->id, 'amount' => 200, 'payment_date' => '2026-11-10', 'payment_method' => 'account', 'shop_account_id' => $this->jazz->id]);

        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-11-10', 'payment_method' => 'cash']);
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'type' => 'repayment', 'amount' => 400, 'transaction_date' => '2026-11-10', 'payment_method' => 'account', 'shop_account_id' => $this->bank->id]);
        // Goods given on credit move no money: nothing picked, nothing counted.
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'type' => 'given', 'amount' => 5000, 'transaction_date' => '2026-11-10']);

        Expense::create(['shop_id' => $this->shop->id, 'description' => 'Tea', 'amount' => 50, 'expense_date' => '2026-11-10', 'payment_method' => 'cash']);
        // A salary expense is the payslip's cost line; the payslip's net pay is the money that left.
        $employee = \App\Models\Employee::create(['shop_id' => $this->shop->id, 'name' => 'Asad', 'rate' => 20000]);
        $payslip = \App\Models\Payslip::create(['shop_id' => $this->shop->id, 'employee_id' => $employee->id, 'period' => '2026-10-01', 'employee_name' => 'Asad', 'salary_type' => 'fixed', 'rate' => 20000, 'salary_cost' => 20000, 'held_deductions' => 2000, 'advance_recovery' => 0, 'net_pay' => 18000, 'status' => 'paid', 'paid_on' => '2026-11-10', 'shop_account_id' => $this->bank->id]);
        Expense::create(['shop_id' => $this->shop->id, 'payslip_id' => $payslip->id, 'description' => 'Salary', 'amount' => 20000, 'expense_date' => '2026-11-10']);

        $stockIn = DB::table('stock_ins')->insertGetId(['shop_id' => $this->shop->id, 'branch_id' => $this->branchId, 'product_name' => 'Charger', 'quantity' => 10, 'stock_date' => '2026-11-10', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_in_payments')->insert(['shop_id' => $this->shop->id, 'stock_in_id' => $stockIn, 'amount' => 2000, 'payment_date' => '2026-11-10', 'payment_method' => 'account', 'shop_account_id' => $this->bank->id, 'source' => 'initial', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('owner_withdrawals')->insert(['shop_id' => $this->shop->id, 'amount' => 100, 'paid_on' => '2026-11-10', 'payment_method' => 'cash', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // Cash: +300 sale −1000 given −50 tea −100 owner = −850. JazzCash: +200. Bank: +400 repaid −18000 net pay −2000 vendor.
        $this->assertSame(-850.0, $this->balance($this->cash()));
        $this->assertSame(200.0, $this->balance('account:'.$this->jazz->id));
        $this->assertSame(-19600.0, $this->balance('account:'.$this->bank->id));

        // A cancelled sale takes its payments with it.
        $sale->update(['status' => 'cancelled']);
        $this->assertSame(-1150.0, $this->balance($this->cash()));
    }

    public function test_money_with_no_method_is_shown_but_kept_out_of_balances(): void
    {
        $repair = Repair::create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'category' => 'mobile', 'description' => 'Screen', 'amount' => 900, 'discount' => 0, 'total' => 900, 'payment_status' => 'paid', 'amount_paid' => 900]);

        $this->assertSame(0.0, $this->balance($this->cash()));
        $unrecorded = $this->flow()->movements()->where('bucket', 'unrecorded');
        $this->assertSame(900.0, (float) $unrecorded->sum('amount'));
        $this->assertStringContainsString('RP-'.str_pad((string) $repair->id, 6, '0', STR_PAD_LEFT), $unrecorded->first()['label']);
    }

    public function test_opening_balance_starts_the_count_and_only_one_is_allowed(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->wallet('cash_in');
        Carbon::setTestNow('2026-11-10 10:00:00');

        MoneyEntries::opening($this->shop->id, 'account:'.$this->jazz->id, 50000, '2026-11-09', $this->owner->id);
        $this->wallet('cash_in');

        // The load before the opening day doesn't count; the one after does.
        $this->assertSame(49000.0, $this->balance('account:'.$this->jazz->id));

        $this->expectException(ValidationException::class);
        MoneyEntries::opening($this->shop->id, 'account:'.$this->jazz->id, 1, '2026-11-10', $this->owner->id);
    }

    public function test_a_count_corrects_the_balance_to_what_was_counted(): void
    {
        MoneyEntries::opening($this->shop->id, $this->cash(), 10000, '2026-11-10', $this->owner->id);
        $this->wallet('cash_in');

        $entry = MoneyEntries::count($this->shop->id, $this->cash(), 11000, '2026-11-10', $this->owner->id, 'Till');

        $this->assertSame(11020.0, (float) $entry->expected);
        $this->assertSame(-20.0, (float) $entry->amount);
        $this->assertSame(11000.0, $this->balance($this->cash()));
        $this->assertSame(-20.0, (float) $this->flow()->balances()->get($this->cash())['last_count']->amount);

        // Cancelling the count puts the expected balance back.
        MoneyEntries::cancel($entry, $this->owner->id);
        $this->assertSame(11020.0, $this->balance($this->cash()));
    }

    public function test_moving_money_takes_it_from_one_place_to_another(): void
    {
        MoneyEntries::transfer($this->shop->id, $this->cash(), 'account:'.$this->bank->id, 7000, '2026-11-10', $this->owner->id, 'Deposit');

        $this->assertSame(-7000.0, $this->balance($this->cash()));
        $this->assertSame(7000.0, $this->balance('account:'.$this->bank->id));

        $this->expectException(ValidationException::class);
        MoneyEntries::transfer($this->shop->id, $this->cash(), $this->cash(), 5, '2026-11-10', $this->owner->id);
    }

    public function test_no_entry_in_the_future(): void
    {
        $this->expectException(ValidationException::class);
        MoneyEntries::opening($this->shop->id, $this->cash(), 5, '2026-11-11', $this->owner->id);
    }

    public function test_the_day_is_pakistan_time(): void
    {
        // 20:00 UTC on the 10th is 01:00 on the 11th in Pakistan.
        Carbon::setTestNow('2026-11-10 20:00:00');
        $this->wallet('cash_in');

        $days = $this->flow()->daily('2026-11-10', '2026-11-11');
        $this->assertArrayHasKey('2026-11-11', $days->all());
        $this->assertArrayNotHasKey('2026-11-10', $days->all());
    }

    public function test_the_daily_flow_shows_cash_and_account_totals_and_fees(): void
    {
        $this->wallet('cash_in');
        $this->wallet('cash_out', ['fee' => 30, 'total' => 1030, 'amount_paid' => 1030]);

        $day = $this->flow()->daily('2026-11-10', '2026-11-10')->get('2026-11-10');
        $this->assertSame(50.0, $day['fees']);
        $this->assertSame(['in' => 1020.0, 'out' => 1000.0, 'net' => 20.0], collect($day['buckets']->get($this->cash()))->only(['in', 'out', 'net'])->all());
        $this->assertSame(['in' => 1030.0, 'out' => 1000.0, 'net' => 30.0], collect($day['buckets']->get('account:'.$this->jazz->id))->only(['in', 'out', 'net'])->all());

        Livewire::test('cash-balances.index')
            ->call('setTab', 'flow')
            ->assertViewHas('totals', fn ($totals) => $totals === ['cashIn' => 1020.0, 'cashOut' => 1000.0, 'accountIn' => 1030.0, 'accountOut' => 1000.0, 'fees' => 50.0]);
    }

    public function test_the_screen_saves_openings_counts_and_moves(): void
    {
        $bucket = 'account:'.$this->jazz->id;

        Livewire::test('cash-balances.index')
            ->call('openOpening', $bucket)
            ->set('openingAmount', '25000')
            ->call('saveOpening')
            ->assertHasNoErrors()
            ->call('openCount', $bucket)
            ->assertSee('Expected at the end of that day')
            ->set('countedAmount', '24900')
            ->call('saveCount')
            ->assertHasNoErrors()
            ->call('openMove')
            ->set('moveFrom', $bucket)
            ->set('moveTo', $this->cash())
            ->set('moveAmount', '900')
            ->call('saveMove')
            ->assertHasNoErrors();

        $this->assertSame(24000.0, $this->balance($bucket));
        $this->assertSame(900.0, $this->balance($this->cash()));
        $this->assertSame(3, MoneyEntry::count());

        // Another shop's account can't be used.
        $other = ShopAccount::withoutGlobalScopes()->create(['shop_id' => Shop::create(['name' => 'Other'])->id, 'name' => 'X', 'provider_type' => 'Bank']);
        Livewire::test('cash-balances.index')
            ->call('openOpening', 'account:'.$other->id)
            ->set('openingAmount', '5')
            ->call('saveOpening')
            ->assertHasErrors('openingAmount');
    }

    public function test_only_people_given_the_screen_can_open_it_and_shops_stay_apart(): void
    {
        $this->get(route('cash-balances.index'))->assertOk()->assertSee('Cash in hand');

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $cashier = User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->actingAs($cashier)->get(route('cash-balances.index'))->assertForbidden();

        $role->update(['permissions' => ['sales', 'cash-balances']]);
        $this->actingAs($cashier->fresh())->get(route('cash-balances.index'))->assertOk();

        $otherShop = Shop::create(['name' => 'Other']);
        $otherOwner = User::factory()->create(['shop_id' => $otherShop->id]);
        $this->actingAs($this->owner);
        $entry = MoneyEntries::opening($this->shop->id, $this->cash(), 500, '2026-11-10', $this->owner->id);

        $this->actingAs($otherOwner);
        $this->assertSame(0.0, (float) (new MoneyFlow($otherShop->id))->balances()->sum('balance'));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('cash-balances.index')->call('cancelEntry', $entry->id);
    }

    public function test_each_branch_keeps_its_own_cash_and_shares_the_accounts(): void
    {
        $second = Branch::withoutGlobalScopes()->create(['shop_id' => $this->shop->id, 'name' => 'Saddar', 'is_main' => false]);
        $this->wallet('cash_in', ['branch_id' => $second->id]);

        $balances = $this->flow()->balances();
        $this->assertSame('Cash · Saddar', $balances->get('cash:'.$second->id)['label']);
        $this->assertSame(1020.0, (float) $balances->get('cash:'.$second->id)['balance']);
        $this->assertSame(0.0, (float) $balances->get($this->cash())['balance']);
        $this->assertSame(-1000.0, (float) $balances->get('account:'.$this->jazz->id)['balance']);
    }

    public function test_mark_as_paid_dates_the_rest_and_knows_a_wallet_loads_way(): void
    {
        $load = $this->wallet('cash_out', ['payment_status' => 'unpaid', 'amount_paid' => 0]);

        Livewire::test('wallet-loads.history')->call('markAsPaid', $load->id);

        $payment = CounterServicePayment::sole();
        $this->assertSame([1020.0, 'account', $this->jazz->id, '2026-11-10'], [(float) $payment->amount, $payment->payment_method, $payment->shop_account_id, $payment->paid_on->toDateString()]);
        $this->assertSame(1020.0, $this->balance('account:'.$this->jazz->id));
    }
}
