<?php

namespace Tests\Feature;

use App\Actions\CustomerDeposits;
use App\Actions\StartLedger;
use App\Enums\BusinessGroup;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\TrialBalanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The chart a new ledger starts with: everything except the accounts only
 * some shops use (Chart::ON_FIRST_USE), which appear the first time posted to.
 * Fixed 2026-11-27: the check compared int array keys to string codes and
 * never matched, so every new chart got the Online accounts.
 */
class ChartOfAccountsFirstUseTest extends TestCase
{
    use RefreshDatabase;

    protected function startLedger(string $category): Shop
    {
        $shop = Shop::create(['name' => 'Fresh '.$category, 'business_category' => $category]);

        if (! $shop->ledger_enabled) {
            $shop->forceFill(['ledger_enabled' => true])->saveQuietly();
            StartLedger::handle($shop);
        }

        return $shop->fresh();
    }

    /** @return list<string> */
    protected function codes(Shop $shop): array
    {
        return LedgerAccount::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('code')->map(fn ($c) => (string) $c)->sort()->values()->all();
    }

    public static function everyType(): array
    {
        return collect(BusinessGroup::cases())->flatMap(fn (BusinessGroup $g) => $g->signupCases())->mapWithKeys(fn ($c) => [$c->value => [$c->value]])->all();
    }

    #[DataProvider('everyType')]
    public function test_a_new_chart_holds_no_first_use_account_and_balances(string $category): void
    {
        $shop = $this->startLedger($category);
        $codes = $this->codes($shop);

        foreach (Chart::ON_FIRST_USE as $code) {
            $this->assertNotContains($code, $codes, "{$category} got {$code} before using it");
        }

        $expected = collect(array_keys(Chart::ACCOUNTS))->map(fn ($c) => (string) $c)->reject(fn ($c) => in_array($c, Chart::ON_FIRST_USE, true))->sort()->values()->all();
        $this->assertSame($expected, $codes);
        $this->assertSame([], (new TrialBalanceReport($shop))->rows()->all());
        $this->assertSame(0, (int) DB::table('ledger_lines')->where('shop_id', $shop->id)->sum('amount_paise'));
    }

    public function test_an_account_is_created_the_first_time_it_is_posted_to(): void
    {
        $shop = $this->startLedger('salon');
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        CustomerDeposits::receive(Customer::create(['name' => 'Hina', 'phone' => '03001234567']), 1000, now()->toDateString(), 'cash', null, $owner->id);

        auth()->logout();
        app(LedgerPoster::class)->drain(limit: 1000);

        $this->assertContains(Chart::CUSTOMER_DEPOSITS, $this->codes($shop));
        $this->assertNotContains(Chart::COURIER_CLEARING, $this->codes($shop));
        $this->assertSame(0, (int) DB::table('ledger_lines')->where('shop_id', $shop->id)->sum('amount_paise'));
    }

    public function test_a_chart_made_before_the_fix_keeps_its_accounts(): void
    {
        $shop = $this->startLedger('kiryana');

        // As every chart was before the fix: the four Online accounts already there.
        foreach ([Chart::TAX_DEDUCTED, Chart::COURIER_CLEARING, Chart::DELIVERY_INCOME, Chart::COURIER_CHARGES] as $code) {
            LedgerAccount::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'code' => $code, 'name' => Chart::ACCOUNTS[$code][0], 'type' => Chart::ACCOUNTS[$code][1]]);
        }

        Chart::ensureFor($shop);

        foreach ([Chart::TAX_DEDUCTED, Chart::COURIER_CLEARING, Chart::DELIVERY_INCOME, Chart::COURIER_CHARGES] as $code) {
            $this->assertContains($code, $this->codes($shop));
        }
    }
}
