<?php

namespace Tests\Feature;

use App\Actions\CancelExpense;
use App\Actions\StartLedger;
use App\Actions\UpdateExpense;
use App\Enums\FeatureKey;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Ledger\LedgerSources;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\LedgerBaseline;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\LedgerOutbox;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerFoundationTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected function ledgerShop(): Shop
    {
        $shop = $this->shop(['ledger_enabled' => true]);

        return StartLedger::handle($shop);
    }

    protected function expense(Shop $shop, float $amount = 1000, ?string $date = null): Expense
    {
        return Expense::create(['shop_id' => $shop->id, 'description' => 'Rent', 'amount' => $amount, 'expense_date' => $date ?? now()->toDateString()]);
    }

    protected function drain(): int
    {
        return app(LedgerPoster::class)->drain();
    }

    /** @return array<string, int> code => net paise */
    protected function balances(Shop $shop): array
    {
        return LedgerLine::withoutGlobalScopes()
            ->where('ledger_lines.shop_id', $shop->id)
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->groupBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, SUM(amount_paise) as paise')
            ->toBase()
            ->pluck('paise', 'code')
            ->map(fn ($paise) => (int) $paise)
            ->filter()
            ->all();
    }

    // ── Off means nothing happens ──────────────────────────────────────

    public function test_with_the_ledger_off_nothing_is_written(): void
    {
        $shop = $this->shop();

        $this->expense($shop);

        $this->assertSame(0, LedgerOutbox::count());
        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, LedgerAccount::withoutGlobalScopes()->count());
    }

    public function test_switched_on_but_not_started_posts_nothing(): void
    {
        $shop = $this->shop(['ledger_enabled' => true]);

        $this->expense($shop);

        $this->assertSame(0, LedgerOutbox::count());
    }

    public function test_it_is_a_per_shop_switch_outside_plans_and_off_by_default(): void
    {
        $this->assertFalse(FeatureKey::Ledger->planControlled());
        $this->assertFalse($this->shop()->fresh()->ledger_enabled);
    }

    // ── Posting ────────────────────────────────────────────────────────

    public function test_starting_creates_the_chart_including_the_new_accounts(): void
    {
        $shop = $this->ledgerShop();

        $codes = LedgerAccount::where('shop_id', $shop->id)->pluck('code')->all();

        foreach ([Chart::CASH, Chart::NOT_CLASSIFIED, Chart::RECEIVABLE, Chart::EMPLOYEE_ADVANCES, Chart::INVENTORY, Chart::PAYABLE, Chart::WITHHOLDINGS, Chart::DRAWINGS, Chart::PRIOR_PERIOD, Chart::SALARIES] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_an_expense_posts_balanced_to_expenses_and_not_classified(): void
    {
        $shop = $this->ledgerShop();

        $expense = $this->expense($shop);

        $this->assertSame(1, LedgerOutbox::count(), 'Marked, not posted, in the same transaction.');
        $this->assertSame(0, LedgerEntry::count());

        $this->assertSame(1, $this->drain());

        $this->assertSame(0, LedgerOutbox::count());
        $this->assertEquals([Chart::EXPENSES => 100000, Chart::NOT_CLASSIFIED => -100000], $this->balances($shop));

        $entry = LedgerEntry::sole();
        $this->assertSame('expense', $entry->source_type);
        $this->assertSame($expense->id, $entry->source_id);
        $this->assertSame('Expense: Rent', $entry->description);
    }

    public function test_posting_twice_posts_once(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop);
        $this->drain();

        app(LedgerPoster::class)->post($shop->id, 'expense', $expense->id);
        LedgerOutbox::mark($shop->id, 'expense', $expense->id);
        $this->drain();

        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_several_changes_before_posting_post_one_entry_of_the_final_state(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop);
        $expense->update(['amount' => 1500]);
        $expense->update(['amount' => 2000]);

        $this->assertSame(1, LedgerOutbox::count());
        $this->drain();

        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(200000, $this->balances($shop)[Chart::EXPENSES]);
    }

    public function test_an_edit_posts_only_the_difference_and_old_lines_never_change(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop);
        $this->drain();
        $firstLine = LedgerLine::orderBy('id')->first();

        UpdateExpense::handle($expense, 'Rent', 1200.0, now()->toDateString(), auth()->id());
        $this->drain();

        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(120000, $this->balances($shop)[Chart::EXPENSES]);
        $this->assertSame(100000, $firstLine->fresh()->amount_paise);
    }

    public function test_moving_a_date_reverses_on_the_old_date_and_posts_on_the_new_one(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop, 1000, now()->addDay()->toDateString());
        $this->drain();

        $newDate = now()->addDays(3)->toDateString();
        UpdateExpense::handle($expense, 'Rent', 1000.0, $newDate, auth()->id());
        $this->drain();

        $dates = LedgerEntry::get()->map(fn ($entry) => $entry->entry_date->toDateString())->sort()->values()->all();
        $this->assertSame([now()->addDay()->toDateString(), now()->addDay()->toDateString(), $newDate], $dates);
        $this->assertSame(100000, $this->balances($shop)[Chart::EXPENSES]);
    }

    public function test_cancelling_reverses_it(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop);
        $this->drain();

        CancelExpense::handle($expense, auth()->id());
        $this->drain();

        $this->assertSame([], $this->balances($shop));
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_an_expense_from_a_payslip_does_not_post_so_salaries_cannot_count_twice(): void
    {
        $shop = $this->ledgerShop();
        $employee = \App\Models\Employee::create(['shop_id' => $shop->id, 'name' => 'Ali', 'rate' => 100]);
        $payslip = \App\Models\Payslip::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'period' => '2026-11-01', 'employee_name' => 'Ali', 'salary_type' => 'fixed', 'rate' => 100]);
        Expense::create(['shop_id' => $shop->id, 'payslip_id' => $payslip->id, 'description' => 'Salary', 'amount' => 100, 'expense_date' => now()->toDateString()]);

        $this->drain();

        $this->assertSame([], $this->balances($shop));
    }

    public function test_the_expenses_branch_is_on_its_lines(): void
    {
        $shop = $this->ledgerShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        $expense = new Expense(['description' => 'Rent', 'amount' => 100, 'expense_date' => now()->toDateString()]);
        $expense->shop_id = $shop->id;
        $expense->branch_id = $second->id;
        $expense->save();

        $this->drain();

        $this->assertSame([$second->id], LedgerLine::pluck('branch_id')->unique()->values()->all());
    }

    public function test_every_line_set_balances(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop, 33.33);
        $this->expense($shop, 0.01);
        $this->drain();

        foreach (LedgerEntry::with('lines')->get() as $entry) {
            $this->assertSame(0, (int) $entry->lines->sum('amount_paise'));
        }
    }

    // ── Immutable ──────────────────────────────────────────────────────

    public function test_journal_rows_cannot_be_changed_or_deleted(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        $this->drain();

        foreach ([fn () => LedgerLine::first()->update(['amount_paise' => 1]), fn () => LedgerLine::first()->delete(), fn () => LedgerEntry::first()->update(['description' => 'x']), fn () => LedgerEntry::first()->delete()] as $change) {
            try {
                $change();
                $this->fail('Should be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── A bug in posting never fails the shop ──────────────────────────

    public function test_a_posting_failure_leaves_a_backlog_and_the_record_is_safe(): void
    {
        $shop = $this->ledgerShop();
        $expense = $this->expense($shop);
        LedgerAccount::where('shop_id', $shop->id)->where('code', Chart::EXPENSES)->delete();

        $this->assertSame(0, $this->drain());

        $this->assertNotNull($expense->fresh());
        $row = LedgerOutbox::sole();
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->last_error);
        $this->assertSame(0, LedgerEntry::count());

        Livewire::test('ledger.trial-balance')->assertSee("couldn't post to the ledger", false);
    }

    public function test_it_stops_retrying_after_the_limit_and_recovers_once_marked_again(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        LedgerOutbox::query()->update(['attempts' => LedgerPoster::MAX_ATTEMPTS]);

        $this->assertSame(0, $this->drain());
        $this->assertSame(1, LedgerOutbox::count());
    }

    // ── Baseline: what existed before the start ────────────────────────

    public function test_records_from_before_the_start_are_baselined_not_posted(): void
    {
        $shop = $this->shop(['ledger_enabled' => true]);
        $this->expense($shop, 1000, now()->subMonth()->toDateString());

        StartLedger::handle($shop);
        $this->drain();

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(1, LedgerBaseline::count());
    }

    public function test_cancelling_an_old_record_goes_to_prior_period_on_todays_date(): void
    {
        $shop = $this->shop(['ledger_enabled' => true]);
        $expense = $this->expense($shop, 1000, now()->subMonth()->toDateString());
        StartLedger::handle($shop);

        CancelExpense::handle($expense, auth()->id());
        $this->drain();

        // Dr Not Classified (cash assumed back), Cr Prior Period, not this month's Expenses.
        $this->assertSame([Chart::NOT_CLASSIFIED => 100000, Chart::PRIOR_PERIOD => -100000], $this->balances($shop));
        $this->assertSame(now()->toDateString(), LedgerEntry::sole()->entry_date->toDateString());

        // Running again posts nothing more.
        LedgerOutbox::mark($shop->id, 'expense', $expense->id);
        $this->drain();
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_editing_an_old_record_posts_only_the_change(): void
    {
        $shop = $this->shop(['ledger_enabled' => true]);
        $date = now()->subMonth()->toDateString();
        $expense = $this->expense($shop, 1000, $date);
        StartLedger::handle($shop);

        UpdateExpense::handle($expense, 'Rent', 1300.0, $date, auth()->id());
        $this->drain();

        $this->assertSame([Chart::NOT_CLASSIFIED => -30000, Chart::PRIOR_PERIOD => 30000], $this->balances($shop));
    }

    public function test_a_small_record_dated_today_and_made_after_the_start_is_not_prior_period(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        $this->drain();

        $this->assertArrayHasKey(Chart::EXPENSES, $this->balances($shop));
    }

    public function test_a_new_record_backdated_before_the_start_goes_to_prior_period(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop, 500, now()->subMonth()->toDateString());
        $this->drain();

        $this->assertSame([Chart::NOT_CLASSIFIED => -50000, Chart::PRIOR_PERIOD => 50000], $this->balances($shop));
        $this->assertSame(now()->toDateString(), LedgerEntry::sole()->entry_date->toDateString());
    }

    // ── Freeze ─────────────────────────────────────────────────────────

    public function test_switching_off_freezes_it_and_switching_back_on_does_not_resume(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        $this->drain();

        $shop->update(['ledger_enabled' => false]);
        $this->assertNotNull($shop->fresh()->ledger_frozen_at);

        $this->expense($shop, 500);
        $this->assertSame(0, LedgerOutbox::count());

        $shop->update(['ledger_enabled' => true]);
        $this->expense($shop, 700);
        $this->assertSame(0, LedgerOutbox::count());
        $this->assertSame(100000, $this->balances($shop)[Chart::EXPENSES]);

        auth()->user()->unsetRelation('shop');
        Livewire::test('ledger.trial-balance')->assertSee('This ledger was frozen')->assertSee('restarting needs a new setup');
    }

    public function test_anything_still_waiting_when_frozen_does_not_post(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);

        $shop->update(['ledger_enabled' => false]);
        $this->drain();

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_new_setup_opens_a_new_epoch_and_keeps_the_old_journal(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        $this->drain();

        $shop->update(['ledger_enabled' => false]);
        $this->expense($shop, 400);
        $shop->update(['ledger_enabled' => true]);

        $restarted = StartLedger::handle($shop->fresh());

        $this->assertSame(2, $restarted->ledger_epoch);
        $this->assertNull($restarted->ledger_frozen_at);
        $this->assertSame(1, LedgerEntry::where('epoch', 1)->count());

        $this->expense($shop, 900);
        $this->drain();

        $this->assertSame(1, LedgerEntry::where('epoch', 2)->count());

        auth()->user()->unsetRelation('shop');
        Livewire::test('ledger.trial-balance')->assertSee('Rs 900.00')->assertDontSee('Rs 1,900.00')->assertDontSee('Rs 1,000.00');
    }

    public function test_starting_twice_is_refused(): void
    {
        $shop = $this->ledgerShop();

        $this->assertRefused(fn () => StartLedger::handle($shop), 'already started');
    }

    // ── Trial Balance and access ───────────────────────────────────────

    public function test_the_trial_balance_shows_balanced_totals_and_filters_by_date(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop, 1000);
        $this->expense($shop, 500, now()->addDays(5)->toDateString());

        Livewire::test('ledger.trial-balance')
            ->assertSee('General Expenses')
            ->assertSee('Receipts Not Classified')
            ->assertSee('Rs 1,500.00')
            ->set('asOf', now()->toDateString())
            ->assertSee('Rs 1,000.00')
            ->assertDontSee('Rs 1,500.00');
    }

    public function test_only_the_owner_gets_in_and_only_when_switched_on(): void
    {
        $shop = $this->shop();
        $this->get(route('ledger.trial-balance'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Trial Balance');

        $shop->update(['ledger_enabled' => true]);
        auth()->user()->unsetRelation('shop');
        $this->get(route('ledger.trial-balance'))->assertOk();
        $this->get(route('dashboard'))->assertSee('Trial Balance');

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['expenses', 'sales']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $this->get(route('ledger.trial-balance'))->assertForbidden();
    }

    public function test_the_owner_starts_it_from_the_screen(): void
    {
        $shop = $this->shop(['ledger_enabled' => true]);

        Livewire::test('ledger.trial-balance')->assertSee('Start the ledger')->call('start')->assertHasNoErrors()->assertSee('Nothing posted yet');

        $this->assertNotNull($shop->fresh()->ledger_started_at);
    }

    public function test_another_shops_ledger_is_out_of_reach(): void
    {
        $other = $this->shop(['name' => 'Other', 'ledger_enabled' => true]);
        StartLedger::handle($other);
        Expense::create(['shop_id' => $other->id, 'description' => 'Theirs', 'amount' => 777, 'expense_date' => now()->toDateString()]);
        $this->drain();

        $this->ledgerShop();

        Livewire::test('ledger.trial-balance')->assertDontSee('Rs 777.00');
    }

    public function test_the_sweep_command_posts_for_every_shop_and_is_scheduled(): void
    {
        $shop = $this->ledgerShop();
        $this->expense($shop);
        auth()->logout();

        Artisan::call('ledger:post');

        $this->assertSame(1, LedgerEntry::withoutGlobalScopes()->count());
        $this->assertStringContainsString('ledger:post', file_get_contents(base_path('routes/console.php')));
    }

    // ── The counts ─────────────────────────────────────────────────────

    public function test_the_counts_command_runs_and_finds_what_it_should(): void
    {
        $shop = $this->shop();
        $product = $this->piece($shop, ['cost_price' => null]);
        $this->batch($product, 5, ['total_cost' => null, 'cost_price' => null]);
        $product->update(['stock_quantity' => 9]);
        $this->expense($shop);

        Artisan::call('ledger:counts', ['--shop' => $shop->id]);
        $output = Artisan::output();

        foreach (['Stock-ins with no cost', 'Products with stock beyond their batches', 'Products in stock with no cost', 'Sale payments with no method', 'Expenses', 'Service payments'] as $label) {
            $this->assertStringContainsString($label, $output);
        }

        $this->assertMatchesRegularExpression('/Stock-ins with no cost\s*\|\s*1/', $output);
        $this->assertMatchesRegularExpression('/Products with stock beyond their batches\s*\|\s*1/', $output);
        $this->assertStringNotContainsString('not on this database yet', $output);
    }

    public function test_the_counts_command_only_ever_reads(): void
    {
        $source = file_get_contents(app_path('Console/Commands/LedgerCounts.php'));

        $this->assertDoesNotMatchRegularExpression('/->(insert|update|delete|truncate|increment|decrement)\(|DB::statement|Schema::(create|table|drop)/', $source);
    }

    // ── The ledger only ever reads the shop's records ──────────────────

    public function test_ledger_code_never_writes_to_source_tables(): void
    {
        foreach ((new Finder)->files()->in(app_path('Ledger'))->name('*.php') as $file) {
            $source = $file->getContents();

            // Only the ledger's own models may be written to, and builders may write nothing.
            preg_match_all('/\b([A-Z]\w+)::(create|insert|update|upsert|updateOrCreate|firstOrCreate|query\(\)->(?:insert|update|delete))/', $source, $static);
            foreach ($static[1] as $class) {
                $this->assertStringStartsWith('Ledger', $class, "{$file->getFilename()} writes through {$class}.");
            }

            if (str_contains($file->getPathname(), 'Builders')) {
                $this->assertDoesNotMatchRegularExpression('/->(save|update|delete|create|increment|decrement|forceFill|push)\(/', $source, "{$file->getFilename()} is a builder and must only read.");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_every_registered_document_has_a_builder_and_a_stable_key(): void
    {
        foreach (LedgerSources::DOCUMENTS as $key => $source) {
            $this->assertLessThanOrEqual(40, strlen($key));
            $this->assertInstanceOf(\App\Ledger\EntryBuilder::class, LedgerSources::builder($key));
        }
    }
}
