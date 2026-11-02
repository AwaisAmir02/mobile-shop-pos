<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Support\StockInPaymentBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The vendor statement reads Stock-Ins (credit) and their dated payment rows
 * (debit). Its "Purchases & payments" closing balance must always equal
 * Vendor::totalOwed() — including the three known cases where cost minus
 * payments differs from StockIn::amountOwed() — and it never writes to
 * Stock-In or its payments.
 */
class PartyLedgerVendorTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwnerVendor(string $name = 'Ali Traders'): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => $name]);
        $this->actingAs($owner);

        return [$shop, $owner, $vendor];
    }

    protected function stockIn(Shop $shop, ?Vendor $vendor, array $attributes): StockIn
    {
        return StockIn::create(array_merge([
            'shop_id' => $shop->id, 'vendor_id' => $vendor?->id, 'product_id' => null, 'product_name' => 'Cable',
            'quantity' => 10, 'quantity_remaining' => 10, 'total_cost' => 1000, 'payment_status' => 'unpaid',
            'amount_paid' => 0, 'stock_date' => now()->subDays(10)->toDateString(),
        ], $attributes));
    }

    protected function pay(StockIn $entry, float $amount, string $date, string $source = 'payment'): void
    {
        StockInPayment::create(['shop_id' => $entry->shop_id, 'stock_in_id' => $entry->id, 'amount' => $amount, 'payment_date' => $date, 'source' => $source]);
        $entry->update(['amount_paid' => (float) $entry->amount_paid + $amount]);
    }

    protected function statementFor(Vendor $vendor, ?string $from = null, ?string $to = null)
    {
        $component = Livewire::test('party-ledger.index')->set('partyType', 'vendor')->set('vendorId', $vendor->id);
        if ($from) {
            $component->set('from', $from);
        }
        if ($to) {
            $component->set('to', $to);
        }

        return $component;
    }

    public function test_stock_ins_are_credits_payments_are_debits_and_the_closing_balance_is_what_we_owe(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $entry = $this->stockIn($shop, $vendor, ['total_cost' => 5000, 'payment_status' => 'partial']);
        $this->pay($entry, 1500, now()->subDays(10)->toDateString(), 'initial');
        $this->pay($entry, 1000, now()->subDays(3)->toDateString());

        $this->statementFor($vendor)
            ->assertViewHas('statement', function ($s) {
                $rows = $s['purchases']['rows'];

                return $rows->pluck('side')->all() === ['credit', 'debit', 'debit']
                    && $rows->pluck('balance')->all() === [5000.0, 3500.0, 2500.0]
                    && $s['purchases']['closing'] === 2500.0
                    && $s['manual']['closing'] === 0.0;
            })
            ->assertSee('Stock-In: Cable × 10')
            ->assertSee('Paid at purchase')
            ->assertSee('Payment made')
            ->assertSee('Purchases &amp; payments', false);

        $this->assertSame(2500.0, $vendor->fresh()->totalOwed());
    }

    public function test_closing_balance_equals_total_owed_even_with_the_three_known_quirks(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();

        // Normal partial: 3000 cost, 1000 paid.
        $normal = $this->stockIn($shop, $vendor, ['total_cost' => 3000, 'payment_status' => 'partial']);
        $this->pay($normal, 1000, now()->subDays(9)->toDateString());

        // Paid with a gap: cost edited up to 2000 after 1200 was paid in full.
        $gap = $this->stockIn($shop, $vendor, ['total_cost' => 2000, 'payment_status' => 'paid']);
        $this->pay($gap, 1200, now()->subDays(8)->toDateString());

        // Overpayment: 1500 paid on a 1000 cost (submitPayment has no cap).
        $over = $this->stockIn($shop, $vendor, ['total_cost' => 1000, 'payment_status' => 'paid']);
        $this->pay($over, 1500, now()->subDays(7)->toDateString());

        // No cost price, but 400 recorded against it.
        $noCost = $this->stockIn($shop, $vendor, ['total_cost' => null, 'payment_status' => 'paid']);
        $this->pay($noCost, 400, now()->subDays(6)->toDateString());

        $expected = $vendor->fresh()->totalOwed();
        $this->assertSame(2000.0, $expected);

        $this->statementFor($vendor)
            ->assertViewHas('statement', fn ($s) => $s['purchases']['closing'] === $expected)
            ->assertSee('Marked paid: remaining balance not owed')
            ->assertSee('Overpayment: not counted by Stock-In')
            ->assertSee('No cost price: payments not counted')
            ->assertSee('No cost price recorded')
            ->assertSee('Computed so this balance matches Stock-In');
    }

    public function test_closing_balance_still_equals_total_owed_before_the_backfill_has_run(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $this->stockIn($shop, $vendor, ['total_cost' => 4000, 'payment_status' => 'partial', 'amount_paid' => 1500]);

        $this->statementFor($vendor)
            ->assertViewHas('statement', fn ($s) => $s['purchases']['closing'] === 2500.0)
            ->assertSee('Adjusted to match Stock-In balance');

        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        $this->statementFor($vendor)
            ->assertViewHas('statement', fn ($s) => $s['purchases']['closing'] === 2500.0)
            ->assertSee('Paid before payment dates were tracked')
            ->assertDontSee('Adjusted to match Stock-In balance');
    }

    public function test_stock_ins_without_a_vendor_and_cancelled_stock_ins_are_left_out(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $this->stockIn($shop, null, ['product_name' => 'No Vendor Item', 'total_cost' => 999]);
        $cancelled = $this->stockIn($shop, $vendor, ['product_name' => 'Cancelled Item', 'total_cost' => 7777, 'payment_status' => 'partial']);
        $this->pay($cancelled, 100, now()->toDateString());
        $cancelled->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now()]);
        $this->stockIn($shop, $vendor, ['product_name' => 'Kept Item', 'total_cost' => 600]);

        $this->statementFor($vendor)
            ->assertDontSee('No Vendor Item')
            ->assertDontSee('Cancelled Item')
            ->assertSee('Kept Item')
            ->assertViewHas('statement', fn ($s) => $s['purchases']['closing'] === 600.0 && $s['purchases']['rows']->count() === 1);

        $this->assertSame(600.0, $vendor->fresh()->totalOwed());
    }

    public function test_entries_before_the_range_are_brought_forward(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $old = $this->stockIn($shop, $vendor, ['total_cost' => 2000, 'stock_date' => '2026-01-05', 'payment_status' => 'partial']);
        $this->pay($old, 500, '2026-01-20');
        $this->pay($old, 300, '2026-03-04');
        $this->stockIn($shop, $vendor, ['total_cost' => 100, 'stock_date' => '2026-05-01']);

        $this->statementFor($vendor, '2026-03-01', '2026-03-31')
            ->assertViewHas('statement', fn ($s) => $s['purchases']['opening'] === 1500.0
                && $s['purchases']['rows']->pluck('balance')->all() === [1200.0]
                && $s['purchases']['closing'] === 1200.0)
            ->assertSee('Balance brought forward');
    }

    public function test_viewing_a_vendor_statement_never_writes_to_stock_in_or_its_payments(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $entry = $this->stockIn($shop, $vendor, ['total_cost' => 2000, 'payment_status' => 'paid']);
        $this->pay($entry, 1200, now()->toDateString());

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b.*\b(stock_ins|stock_in_payments)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $component = $this->statementFor($vendor);
        $this->callProtected($component->instance(), 'exportSections', [true]);

        $this->assertSame([], $writes);
        $this->assertSame(1200.0, (float) $entry->fresh()->amount_paid);
        $this->assertSame(1, StockInPayment::count());
    }

    public function test_vendor_opening_and_adjustment_entries_form_their_own_balance(): void
    {
        [$shop, $owner, $vendor] = $this->shopOwnerVendor();
        $this->stockIn($shop, $vendor, ['total_cost' => 1000]);

        $this->statementFor($vendor)
            ->call('openAddEntry')
            ->assertSet('entryKind', 'opening')
            ->assertSet('entryDirection', 'credit')
            ->assertSee('Credit: we owe the vendor more')
            ->assertSee('Debit: we owe less')
            ->set('entryAmount', '5000')
            ->set('entryDate', now()->subYear()->toDateString())
            ->set('entryNote', 'Old supplier khata')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->call('openAddEntry')
            ->assertSet('entryKind', 'adjustment')
            ->set('entryDirection', 'debit')
            ->set('entryAmount', '700')
            ->set('entryNote', 'Settled in cash, not via Stock-In')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertViewHas('statement', fn ($s) => $s['manual']['closing'] === 4300.0 && $s['purchases']['closing'] === 1000.0);

        $entry = PartyLedgerEntry::where('kind', 'opening')->sole();
        $this->assertSame($vendor->id, $entry->vendor_id);
        $this->assertNull($entry->customer_id);
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame(1000.0, $vendor->fresh()->totalOwed());
    }

    public function test_a_vendor_and_a_customer_each_have_their_own_single_opening_balance(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        PartyLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'kind' => 'opening', 'direction' => 'debit', 'amount' => 100, 'entry_date' => now()->toDateString(), 'note' => 'Customer opening']);

        $this->statementFor($vendor)
            ->set('entryKind', 'opening')->set('entryDirection', 'credit')->set('entryAmount', '200')->set('entryNote', 'Vendor opening')
            ->call('saveEntry')->assertHasNoErrors()
            ->set('entryKind', 'opening')->set('entryAmount', '300')->set('entryNote', 'Second vendor opening')
            ->call('saveEntry')->assertHasErrors('entryKind');

        $this->assertSame(2, PartyLedgerEntry::count());
    }

    public function test_an_entry_must_belong_to_exactly_one_customer_or_vendor(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $base = ['shop_id' => $shop->id, 'kind' => 'adjustment', 'direction' => 'debit', 'amount' => 10, 'entry_date' => now()->toDateString(), 'note' => 'x'];

        foreach ([[], ['customer_id' => $customer->id, 'vendor_id' => $vendor->id]] as $parties) {
            try {
                PartyLedgerEntry::create($base + $parties);
                $this->fail('Expected an entry with '.count($parties).' parties to be refused.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('exactly one', $e->getMessage());
            }
        }

        $this->assertSame(0, PartyLedgerEntry::count());
    }

    public function test_cancelling_a_vendor_entry_is_scoped_to_that_vendor(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $other = Vendor::create(['shop_id' => $shop->id, 'name' => 'Other Vendor']);
        $entry = PartyLedgerEntry::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'kind' => 'adjustment', 'direction' => 'credit', 'amount' => 50, 'entry_date' => now()->toDateString(), 'note' => 'x']);

        $this->statementFor($vendor)
            ->call('openCancelEntry', $entry->id)
            ->set('cancelReason', 'Wrong vendor')
            ->call('cancelEntry')
            ->assertHasNoErrors()
            ->assertViewHas('statement', fn ($s) => $s['manual']['closing'] === 0.0);

        $this->assertTrue(PartyLedgerEntry::withoutGlobalScopes()->find($entry->id)->isCancelled());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->statementFor($other)->call('openCancelEntry', $entry->id);
    }

    public function test_the_vendor_side_needs_vendors_access_as_well_as_party_ledger_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Traders']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Accountant', 'permissions' => ['party-ledger']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::withQueryParams(['party' => 'vendor', 'vendor' => $vendor->id])
            ->test('party-ledger.index')
            ->assertSet('partyType', 'customer')
            ->assertViewHas('vendorSide', false)
            ->assertDontSee('Ali Traders');

        $role->update(['permissions' => ['party-ledger', 'vendors']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::withQueryParams(['party' => 'vendor', 'vendor' => $vendor->id])
            ->test('party-ledger.index')
            ->assertViewHas('vendorSide', true)
            ->assertSee('Ali Traders');
    }

    public function test_vendor_statements_are_isolated_between_shops(): void
    {
        $this->shopOwnerVendor('Shop A Vendor');
        $shopB = Shop::create(['name' => 'Shop B']);
        $vendorB = Vendor::create(['shop_id' => $shopB->id, 'name' => 'Shop B Vendor']);
        $this->stockIn($shopB, $vendorB, ['total_cost' => 9999]);

        Livewire::test('party-ledger.index')
            ->set('partyType', 'vendor')
            ->assertSee('Shop A Vendor')
            ->assertDontSee('Shop B Vendor')
            ->set('vendorId', $vendorB->id)
            ->assertViewHas('statement', null)
            ->assertDontSee('9,999.00');
    }

    public function test_vendor_export_has_purchases_and_adjustments_sections_and_a_vendor_title(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $entry = $this->stockIn($shop, $vendor, ['total_cost' => 800, 'payment_status' => 'partial']);
        $this->pay($entry, 300, now()->toDateString());

        $component = $this->statementFor($vendor);
        $sections = $this->callProtected($component->instance(), 'exportSections', [false]);

        $this->assertSame(['Purchases & payments', 'Opening & adjustments'], array_column($sections, 'title'));
        $this->assertSame(['Date', 'Description', 'Debit', 'Credit', 'Balance'], $sections[0]['headers']);
        $this->assertSame('Party Ledger — Vendor: Ali Traders', $this->callProtected($component->instance(), 'exportTitle'));

        $closing = collect($sections[0]['rows'])->last();
        $this->assertSame(['Closing balance', 'Rs 300.00', 'Rs 800.00', 'Rs 500.00'], array_slice($closing, 1));

        $component->call('exportPdf', app(\App\Services\TableExportService::class))->assertFileDownloaded();
        $component->call('exportExcel', app(\App\Services\TableExportService::class))->assertFileDownloaded();
    }

    public function test_a_vendor_with_ledger_entries_cannot_be_deleted_even_if_they_are_cancelled(): void
    {
        [$shop, , $vendor] = $this->shopOwnerVendor();
        $entry = PartyLedgerEntry::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'kind' => 'opening', 'direction' => 'credit', 'amount' => 100, 'entry_date' => now()->toDateString(), 'note' => 'x']);
        $entry->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now()]);

        Livewire::test('vendors.index')->call('delete', $vendor->id);

        $this->assertNotNull(Vendor::find($vendor->id));

        $free = Vendor::create(['shop_id' => $shop->id, 'name' => 'No History']);
        Livewire::test('vendors.index')->call('delete', $free->id);
        $this->assertNull(Vendor::find($free->id));
    }

    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
