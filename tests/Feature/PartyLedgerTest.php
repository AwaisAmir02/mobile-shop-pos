<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CancelUdhaarTransaction;
use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Party Ledger is a per-customer statement with three separate running
 * balances — Sales, Udhaar, and Opening & adjustments — that are never
 * combined. The Sales and Udhaar balances are read straight from their own
 * modules and must always equal the figures those modules show.
 */
class PartyLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwnerCustomer(string $name = 'Bilal Khan'): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => $name]);
        $this->actingAs($owner);

        return [$shop, $owner, $customer];
    }

    protected function sale(Shop $shop, User $owner, ?Customer $customer, float $total, ?string $date = null): Sale
    {
        $sale = new Sale([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer?->id,
            'subtotal' => $total, 'discount_amount' => 0, 'total' => $total,
        ]);
        if ($date) {
            $sale->created_at = Carbon::parse($date)->setTime(12, 0);
        }
        $sale->save();

        return $sale;
    }

    protected function payment(Sale $sale, float $amount, string $date): void
    {
        SalePayment::create([
            'shop_id' => $sale->shop_id, 'sale_id' => $sale->id, 'user_id' => $sale->user_id,
            'amount' => $amount, 'payment_date' => $date,
        ]);
    }

    protected function udhaar(Customer $customer, string $type, float $amount, string $date): UdhaarTransaction
    {
        return UdhaarTransaction::create([
            'shop_id' => $customer->shop_id, 'customer_id' => $customer->id, 'type' => $type,
            'amount' => $amount, 'transaction_date' => $date,
        ]);
    }

    protected function statementFor(Customer $customer, ?string $from = null, ?string $to = null)
    {
        $component = Livewire::test('party-ledger.index')->set('customerId', $customer->id);
        if ($from) {
            $component->set('from', $from);
        }
        if ($to) {
            $component->set('to', $to);
        }

        return $component;
    }

    public function test_sales_and_udhaar_running_balances_are_never_combined_for_a_customer(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();

        $sale = $this->sale($shop, $owner, $customer, 1000);
        $this->payment($sale, 400, now()->toDateString());
        $this->udhaar($customer, 'given', 500, now()->toDateString());

        $this->statementFor($customer)
            ->assertViewHas('statement', fn ($s) => $s['sales']['closing'] === 600.0 && $s['udhaar']['closing'] === 500.0 && $s['manual']['closing'] === 0.0)
            ->assertSee('Rs 600.00')
            ->assertSee('Rs 500.00')
            ->assertDontSee('Rs 1,100.00');
    }

    public function test_closing_balances_always_equal_the_customer_profile_and_udhaar_figures(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();

        $first = $this->sale($shop, $owner, $customer, 2500, now()->subMonths(5)->toDateString());
        $this->payment($first, 1000, now()->subMonths(5)->toDateString());
        $this->payment($first, 700, now()->subMonth()->toDateString());
        $this->sale($shop, $owner, $customer, 800, now()->subDays(3)->toDateString());
        $cancelled = $this->sale($shop, $owner, $customer, 9999);
        CancelSale::handle($cancelled, $owner->id, null);

        $given = $this->udhaar($customer, 'given', 3000, now()->subMonths(4)->toDateString());
        $this->udhaar($customer, 'repayment', 1200, now()->subMonths(2)->toDateString());
        $reversed = $this->udhaar($customer, 'given', 400, now()->subWeek()->toDateString());
        $this->udhaar($customer, 'given_reversal', 400, now()->subDays(6)->toDateString())->update(['reverses_transaction_id' => $reversed->id]);
        CancelUdhaarTransaction::handle($this->udhaar($customer, 'given', 7777, now()->toDateString()), $owner->id, 'typo');

        PartyLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'opening',
            'direction' => 'debit', 'amount' => 5000, 'entry_date' => now()->subYear()->toDateString(), 'note' => 'Old register',
        ]);

        $customer = $customer->fresh();
        $expectedSales = $customer->salesOutstandingBalance();
        $expectedUdhaar = $customer->udhaarBalance();

        $this->assertSame(1600.0, $expectedSales);
        $this->assertSame(1800.0, $expectedUdhaar);

        $this->statementFor($customer)
            ->assertViewHas('statement', fn ($s) => $s['sales']['closing'] === $expectedSales
                && $s['udhaar']['closing'] === $expectedUdhaar
                && $s['manual']['closing'] === 5000.0);
    }

    public function test_entries_before_the_range_are_brought_forward_and_entries_after_it_are_left_out(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();

        $this->sale($shop, $owner, $customer, 1000, '2026-01-10');
        $inRange = $this->sale($shop, $owner, $customer, 300, '2026-03-05');
        $this->payment($inRange, 100, '2026-03-06');
        $this->sale($shop, $owner, $customer, 50, '2026-05-01');
        $this->udhaar($customer, 'given', 200, '2026-02-01');
        $this->udhaar($customer, 'repayment', 250, '2026-03-10');

        $this->statementFor($customer, '2026-03-01', '2026-03-31')
            ->assertViewHas('statement', function ($s) {
                return $s['sales']['opening'] === 1000.0
                    && $s['sales']['rows']->pluck('balance')->all() === [1300.0, 1200.0]
                    && $s['sales']['closing'] === 1200.0
                    && $s['udhaar']['opening'] === 200.0
                    && $s['udhaar']['closing'] === -50.0;
            })
            ->assertSee('Balance brought forward')
            ->assertSee('Rs 50.00 (advance)')
            ->assertSee('INV-'.str_pad((string) $inRange->id, 6, '0', STR_PAD_LEFT));
    }

    public function test_walk_in_sales_are_not_a_party_and_never_appear_on_a_statement(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();

        $this->sale($shop, $owner, null, 300);
        $this->sale($shop, $owner, null, 200);
        $this->sale($shop, $owner, $customer, 1000);

        $this->statementFor($customer)
            ->assertDontSee('Walk-in')
            ->assertViewHas('statement', fn ($s) => $s['sales']['closing'] === 1000.0 && $s['sales']['rows']->count() === 1);
    }

    public function test_the_earnings_panel_is_gone(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();
        $this->sale($shop, $owner, $customer, 1000);

        $this->statementFor($customer)
            ->assertDontSee('Earnings')
            ->assertDontSee('Commission')
            ->assertViewMissing('salesRevenue');
    }

    public function test_with_no_customer_chosen_the_screen_asks_for_one(): void
    {
        $this->shopOwnerCustomer();

        Livewire::test('party-ledger.index')
            ->assertSee('Choose a customer')
            ->assertViewHas('statement', null);
    }

    public function test_manual_entries_form_their_own_balance_and_never_touch_sales_or_udhaar(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();
        $this->sale($shop, $owner, $customer, 1000);
        $this->udhaar($customer, 'given', 500, now()->toDateString());

        $component = $this->statementFor($customer)
            ->call('openAddEntry')
            ->assertSet('entryKind', 'opening')
            ->set('entryDirection', 'debit')
            ->set('entryAmount', '2000')
            ->set('entryDate', now()->subYear()->toDateString())
            ->set('entryNote', 'Balance from old register')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->call('openAddEntry')
            ->assertSet('entryKind', 'adjustment')
            ->set('entryDirection', 'credit')
            ->set('entryAmount', '150')
            ->set('entryNote', 'Discount agreed')
            ->call('saveEntry')
            ->assertHasNoErrors();

        $component->assertViewHas('statement', fn ($s) => $s['manual']['closing'] === 1850.0
            && $s['sales']['closing'] === 1000.0
            && $s['udhaar']['closing'] === 500.0);

        $entry = PartyLedgerEntry::where('kind', 'adjustment')->first();
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame($shop->id, $entry->shop_id);
        $this->assertSame(1000.0, $customer->fresh()->salesOutstandingBalance());
        $this->assertSame(500.0, $customer->fresh()->udhaarBalance());
    }

    public function test_a_customer_can_have_only_one_opening_balance_that_is_not_cancelled(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();
        $opening = PartyLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'opening',
            'direction' => 'debit', 'amount' => 100, 'entry_date' => now()->toDateString(), 'note' => 'First',
        ]);

        $this->statementFor($customer)
            ->set('entryKind', 'opening')
            ->set('entryAmount', '200')
            ->set('entryNote', 'Second')
            ->call('saveEntry')
            ->assertHasErrors('entryKind');

        $this->assertSame(1, PartyLedgerEntry::count());

        $opening->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Wrong amount']);

        $this->statementFor($customer)
            ->set('entryKind', 'opening')
            ->set('entryAmount', '200')
            ->set('entryNote', 'Corrected')
            ->call('saveEntry')
            ->assertHasNoErrors();
    }

    public function test_an_entry_needs_a_note_and_a_positive_amount_dated_no_later_than_today(): void
    {
        [, , $customer] = $this->shopOwnerCustomer();

        $this->statementFor($customer)
            ->set('entryAmount', '0')
            ->set('entryDate', now()->addDay()->toDateString())
            ->set('entryNote', '')
            ->call('saveEntry')
            ->assertHasErrors(['entryAmount', 'entryDate', 'entryNote']);

        $this->assertSame(0, PartyLedgerEntry::count());
    }

    public function test_cancelling_an_entry_needs_a_reason_and_keeps_the_record(): void
    {
        [$shop, $owner, $customer] = $this->shopOwnerCustomer();
        $entry = PartyLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'adjustment',
            'direction' => 'debit', 'amount' => 300, 'entry_date' => now()->toDateString(), 'note' => 'Mistake',
        ]);

        $component = $this->statementFor($customer)
            ->call('openCancelEntry', $entry->id)
            ->call('cancelEntry')
            ->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Entered twice')
            ->call('cancelEntry')
            ->assertHasNoErrors();

        $component->assertViewHas('statement', fn ($s) => $s['manual']['closing'] === 0.0 && $s['manual']['rows']->isEmpty());

        $stored = PartyLedgerEntry::withoutGlobalScope(ExcludesCancelledScope::class)->find($entry->id);
        $this->assertNotNull($stored);
        $this->assertSame(RecordStatus::Cancelled, $stored->status);
        $this->assertSame('Entered twice', $stored->cancel_reason);
        $this->assertSame($owner->id, $stored->cancelled_by);
        $this->assertSame(300.0, (float) $stored->amount);
    }

    public function test_party_ledger_is_isolated_between_shops(): void
    {
        [$shopA, , $customerA] = $this->shopOwnerCustomer('Shop A Customer');
        $shopB = Shop::create(['name' => 'Shop B']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);
        $this->udhaar($customerB, 'given', 9999, now()->toDateString());
        $entryB = PartyLedgerEntry::create([
            'shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'kind' => 'opening',
            'direction' => 'debit', 'amount' => 8888, 'entry_date' => now()->toDateString(), 'note' => 'B only',
        ]);

        Livewire::test('party-ledger.index')
            ->assertSee('Shop A Customer')
            ->assertDontSee('Shop B Customer')
            ->set('customerId', $customerB->id)
            ->assertViewHas('statement', null)
            ->assertDontSee('9,999.00')
            ->assertDontSee('8,888.00');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->statementFor($customerA)->call('openCancelEntry', $entryB->id);
    }

    public function test_a_role_without_party_ledger_access_cannot_reach_the_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('party-ledger.index'))->assertForbidden();
    }

    public function test_a_role_without_party_ledger_access_cannot_add_or_cancel_entries(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('party-ledger.index')
            ->set('customerId', $customer->id)
            ->set('entryAmount', '100')
            ->set('entryNote', 'Should not save')
            ->call('saveEntry')
            ->assertForbidden();

        $this->assertSame(0, PartyLedgerEntry::count());

        $entry = PartyLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'opening',
            'direction' => 'debit', 'amount' => 100, 'entry_date' => now()->toDateString(), 'note' => 'Opening',
        ]);

        Livewire::test('party-ledger.index')
            ->set('customerId', $customer->id)
            ->set('cancelingEntryId', $entry->id)
            ->set('cancelReason', 'Should not cancel')
            ->call('cancelEntry')
            ->assertForbidden();

        $this->assertFalse($entry->fresh()->isCancelled());
    }

    public function test_a_role_with_party_ledger_access_can_reach_the_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Accountant', 'permissions' => ['party-ledger']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('party-ledger.index'))->assertOk();
    }

    public function test_a_customer_with_ledger_entries_cannot_be_deleted(): void
    {
        [$shop, , $customer] = $this->shopOwnerCustomer();
        $this->assertFalse($customer->hasFinancialHistory());

        $entry = PartyLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'opening',
            'direction' => 'debit', 'amount' => 100, 'entry_date' => now()->toDateString(), 'note' => 'Opening',
        ]);
        $this->assertTrue($customer->hasFinancialHistory());

        $entry->update(['status' => RecordStatus::Cancelled]);
        $this->assertTrue($customer->fresh()->hasFinancialHistory());
    }
}
