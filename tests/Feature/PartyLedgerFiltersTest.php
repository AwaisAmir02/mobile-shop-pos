<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Party Ledger: the Customers/Vendors switch, the "with a balance"
 * filter, date shortcuts, the customer's read-only "other amounts owed"
 * list, vendor links to Stock-In history, and going live through the Super
 * Admin's per-shop Module access switch.
 */
class PartyLedgerFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function sale(Shop $shop, User $owner, Customer $customer, float $total, float $paid = 0): Sale
    {
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => $total, 'discount_amount' => 0, 'total' => $total]);
        if ($paid > 0) {
            SalePayment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'user_id' => $owner->id, 'amount' => $paid, 'payment_date' => now()->toDateString()]);
        }

        return $sale;
    }

    protected function stockIn(Shop $shop, Vendor $vendor, array $attributes = []): StockIn
    {
        return StockIn::create(array_merge([
            'shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 1, 'quantity_remaining' => 1,
            'total_cost' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => now()->subDays(3)->toDateString(),
        ], $attributes));
    }

    // ── Customers / Vendors switch ───────────────────────────────────

    public function test_the_switch_moves_between_the_customer_and_vendor_pickers(): void
    {
        [$shop] = $this->shopOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Customer']);
        Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Vendor']);

        Livewire::test('party-ledger.index')
            ->assertSee('Customers')->assertSee('Vendors')
            ->assertSee('Bilal Customer')->assertDontSee('Ali Vendor')
            ->call('setPartyType', 'vendor')
            ->assertSet('partyType', 'vendor')
            ->assertSee('Ali Vendor')->assertDontSee('Bilal Customer')
            ->assertSee('Choose a vendor')
            ->call('setPartyType', 'customer')
            ->assertSee('Bilal Customer');
    }

    public function test_without_vendors_access_there_is_no_switch_and_it_cannot_be_forced(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Vendor']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Accountant', 'permissions' => ['party-ledger']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::test('party-ledger.index')
            ->assertDontSee('aria-label="Party type"', false)
            ->call('setPartyType', 'vendor')
            ->assertSet('partyType', 'customer')
            ->assertDontSee('Ali Vendor');
    }

    // ── "With a balance" filter ──────────────────────────────────────

    public function test_the_balance_filter_lists_only_customers_with_a_balance_and_keeps_the_chosen_one(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $salesOwed = Customer::create(['shop_id' => $shop->id, 'name' => 'Owes On Sales']);
        $udhaar = Customer::create(['shop_id' => $shop->id, 'name' => 'Owes Udhaar']);
        $manual = Customer::create(['shop_id' => $shop->id, 'name' => 'Has Opening Only']);
        $settled = Customer::create(['shop_id' => $shop->id, 'name' => 'Fully Settled']);
        Customer::create(['shop_id' => $shop->id, 'name' => 'No History']);

        $this->sale($shop, $owner, $salesOwed, 1000, 400);
        $this->sale($shop, $owner, $settled, 500, 500);
        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $udhaar->id, 'type' => 'given', 'amount' => 300, 'transaction_date' => now()->toDateString()]);
        PartyLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $manual->id, 'kind' => 'opening', 'direction' => 'debit', 'amount' => 50, 'entry_date' => now()->toDateString(), 'note' => 'x']);

        $component = Livewire::test('party-ledger.index')->set('withBalanceOnly', true);
        $component->assertViewHas('customers', fn ($customers) => $customers->pluck('name')->sort()->values()->all() === ['Has Opening Only', 'Owes On Sales', 'Owes Udhaar']);

        $component->set('customerId', $settled->id)
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('name')->contains('Fully Settled') && ! $customers->pluck('name')->contains('No History'));
    }

    public function test_the_balance_filter_lists_only_vendors_we_owe_or_with_their_own_adjustments(): void
    {
        [$shop] = $this->shopOwner();
        $owed = Vendor::create(['shop_id' => $shop->id, 'name' => 'Owed Vendor']);
        $paid = Vendor::create(['shop_id' => $shop->id, 'name' => 'Paid Vendor']);
        $manual = Vendor::create(['shop_id' => $shop->id, 'name' => 'Opening Only Vendor']);
        $this->stockIn($shop, $owed);
        $this->stockIn($shop, $paid, ['payment_status' => 'paid', 'amount_paid' => 1000]);
        PartyLedgerEntry::create(['shop_id' => $shop->id, 'vendor_id' => $manual->id, 'kind' => 'opening', 'direction' => 'credit', 'amount' => 70, 'entry_date' => now()->toDateString(), 'note' => 'x']);

        Livewire::test('party-ledger.index')
            ->call('setPartyType', 'vendor')
            ->set('withBalanceOnly', true)
            ->assertViewHas('vendors', fn ($vendors) => $vendors->pluck('name')->sort()->values()->all() === ['Opening Only Vendor', 'Owed Vendor']);
    }

    // ── Date shortcuts ───────────────────────────────────────────────

    public function test_date_shortcuts_set_the_range_and_typing_a_date_clears_the_shortcut(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'given', 'amount' => 100, 'transaction_date' => '2025-02-14']);
        $other = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana']);
        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $other->id, 'type' => 'given', 'amount' => 100, 'transaction_date' => '2024-07-01']);
        $today = now()->toDateString();

        $component = Livewire::test('party-ledger.index')
            ->assertSet('rangePreset', '3months')
            ->call('applyPreset', 'month')
            ->assertSet('from', now()->startOfMonth()->toDateString())->assertSet('to', $today)
            ->call('applyPreset', 'year')
            ->assertSet('from', now()->startOfYear()->toDateString())
            ->set('customerId', $customer->id)
            ->call('applyPreset', 'all')
            ->assertSet('rangePreset', 'all')
            ->assertSet('from', '2025-02-14')
            ->assertViewHas('statement', fn ($s) => $s['udhaar']['opening'] === 0.0 && $s['udhaar']['rows']->count() === 1);

        // "All time" follows the chosen party.
        $component->set('customerId', $other->id)->assertSet('from', '2024-07-01');

        $component->set('from', '2026-01-01')->assertSet('rangePreset', '');
    }

    // ── Other amounts owed ───────────────────────────────────────────

    public function test_other_amounts_owed_are_listed_read_only_and_never_counted_in_a_balance(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->sale($shop, $owner, $customer, 800);
        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500, 'payment_status' => 'partial', 'amount_paid' => 200]);
        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Zong', 'load_type' => 'balance', 'amount' => 900, 'total' => 900, 'payment_status' => 'paid', 'amount_paid' => 900]);

        $component = Livewire::test('party-ledger.index')->set('customerId', $customer->id)
            ->assertSee('Other amounts owed')
            ->assertSee('Balance Load')
            ->assertSee('Jazz')
            ->assertDontSee('Zong')
            ->assertSee('Record payments on the Udhaar screen')
            ->assertViewHas('otherDues', fn ($dues) => $dues->count() === 1 && $dues->first()['amountDue'] === 300.0)
            ->assertViewHas('statement', fn ($s) => $s['sales']['closing'] === 800.0 && $s['udhaar']['closing'] === 0.0 && $s['manual']['closing'] === 0.0);

        $sections = $this->callProtected($component->instance(), 'exportSections', [false]);
        $this->assertSame('Other amounts owed (not part of the balances above)', end($sections)['title']);
        $this->assertSame('Rs 300.00', end($sections)['rows'][0][3]);
    }

    public function test_a_customer_with_no_other_dues_gets_no_list_and_no_extra_export_section(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->sale($shop, $owner, $customer, 800);

        $component = Livewire::test('party-ledger.index')->set('customerId', $customer->id)->assertDontSee('Other amounts owed');

        $this->assertSame(['Sales', 'Udhaar', 'Opening & adjustments'], array_column($this->callProtected($component->instance(), 'exportSections', [false]), 'title'));
    }

    // ── Vendor links ─────────────────────────────────────────────────

    public function test_vendor_rows_link_to_stock_in_history_filtered_to_that_vendor(): void
    {
        [$shop] = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Traders']);
        $other = Vendor::create(['shop_id' => $shop->id, 'name' => 'Other Traders']);
        $this->stockIn($shop, $vendor, ['product_name' => 'Ali Cable']);
        $this->stockIn($shop, $other, ['product_name' => 'Other Cable']);
        $url = route('stock-ins.history', ['vendorFilter' => $vendor->id]);

        Livewire::test('party-ledger.index')->call('setPartyType', 'vendor')->set('vendorId', $vendor->id)
            ->assertViewHas('statement', fn ($s) => $s['purchases']['rows']->every(fn ($row) => $row['url'] === $url))
            ->assertSeeHtml('href="'.e($url).'"');

        // The existing history screen already honours that URL parameter.
        Livewire::withQueryParams(['vendorFilter' => (string) $vendor->id])->test('stock-ins.history')
            ->assertSee('Ali Cable')
            ->assertDontSee('Other Cable');
    }

    public function test_vendor_rows_have_no_link_without_stock_in_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Traders']);
        $this->stockIn($shop, $vendor);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Accountant', 'permissions' => ['party-ledger', 'vendors']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::test('party-ledger.index')->call('setPartyType', 'vendor')->set('vendorId', $vendor->id)
            ->assertViewHas('statement', fn ($s) => $s['purchases']['rows']->every(fn ($row) => $row['url'] === null));
    }

    // ── Going live ───────────────────────────────────────────────────

    public function test_party_ledger_goes_live_per_shop_through_the_super_admin_module_access_switch(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'disabled_screens' => ['party-ledger']]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);

        $this->actingAs($owner);
        $this->get(route('party-ledger.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('party-ledger.index'));

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'party-ledger');
        $this->assertSame([], $shop->fresh()->disabled_screens);

        $this->actingAs($owner->fresh());
        $this->get(route('party-ledger.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('party-ledger.index'));
    }

    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
