<?php

namespace Tests\Feature;

use App\Actions\CustomerDeposits;
use App\Actions\GiftVouchers;
use App\Actions\StartLedger;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\LedgerAccount;
use App\ReportQueries\TrialBalanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Services, memberships, deposits and vouchers in the double-entry ledger. */
class ServiceTradesLedgerTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('salon');

        if (! $this->shop->ledger_enabled) {
            $this->shop->forceFill(['ledger_enabled' => true])->saveQuietly();
            StartLedger::handle($this->shop);
        }
    }

    protected function balance(string $code): float
    {
        $id = LedgerAccount::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('code', $code)->value('id');

        return $id === null ? 0.0 : round(DB::table('ledger_lines')->where('ledger_account_id', $id)->sum('amount_paise') / 100, 2);
    }

    public function test_every_service_money_path_posts_and_balances(): void
    {
        $deposit = CustomerDeposits::receive($this->client, 1000, now()->toDateString(), 'cash', null, $this->owner->id);
        $voucher = GiftVouchers::sell(['amount' => 2000], $this->owner->id, $this->shop->id);
        $this->bill([$this->serviceLine($this->facial, $this->ali), ['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], [[$this->shampoo, 1]],
            paid: 5700, prepaid: [['method' => 'deposit', 'id' => $deposit->id, 'amount' => 1000], ['method' => 'gift_voucher', 'id' => $voucher->id, 'amount' => 2000]]);
        $kept = CustomerDeposits::receive($this->client, 300, now()->toDateString(), 'cash', null, null);
        CustomerDeposits::forfeit($kept, now()->toDateString());

        auth()->logout();
        app(LedgerPoster::class)->drain(limit: 10000);

        $this->assertNull(DB::table('ledger_outbox')->whereNotNull('last_error')->value('last_error'));
        $this->assertSame(0, (int) DB::table('ledger_lines')->where('shop_id', $this->shop->id)->sum('amount_paise'));
        $this->assertSame(-7500.0, $this->balance(Chart::SERVICE_SALES), 'Facial + membership');
        $this->assertSame(-1200.0, $this->balance(Chart::SALES), 'Only the product');
        $this->assertSame(0.0, $this->balance(Chart::CUSTOMER_DEPOSITS));
        $this->assertSame(0.0, $this->balance(Chart::GIFT_VOUCHERS));
        $this->assertSame(-300.0, $this->balance(Chart::FORFEITED_DEPOSITS));
        $this->assertSame(0.0, $this->balance(Chart::RECEIVABLE), 'The bill was paid in full: cash, deposit and voucher');
    }

    public function test_a_shop_without_service_lines_gets_no_new_accounts(): void
    {
        $this->bill([], [[$this->shampoo, 1]], paid: 1200);
        auth()->logout();
        app(LedgerPoster::class)->drain(limit: 10000);

        $codes = LedgerAccount::withoutGlobalScopes()->where('shop_id', $this->shop->id)->pluck('code')->all();
        $this->assertNotContains(Chart::CUSTOMER_DEPOSITS, $codes);
        $this->assertNotContains(Chart::GIFT_VOUCHERS, $codes);
        $this->assertNotContains(Chart::SERVICE_SALES, $codes);
    }
}
