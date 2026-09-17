<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Confirms each of the remaining three export points (Stock In History,
 * Udhaar main list, Udhaar per-customer, Party Ledger) correctly wires its
 * own filters/columns into the shared export mechanism already proven
 * correct by TableExportTest — these don't re-test the mechanism itself.
 */
class TableExportPerScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Invokes a protected/private method on a Livewire component instance directly. */
    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }

    // ── Stock In History ─────────────────────────────────────────────

    public function test_stock_in_history_export_respects_the_payment_status_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'Paid Item', 'quantity' => 5, 'stock_date' => now()->toDateString(), 'payment_status' => 'paid', 'amount_paid' => 100]);
        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'Unpaid Item', 'quantity' => 3, 'stock_date' => now()->toDateString(), 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $this->actingAs($owner);

        $component = Livewire::test('stock-ins.history')->set('paymentStatusFilter', 'unpaid');
        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);

        $this->assertCount(1, $rows);
        $this->assertSame('Unpaid Item', $rows[0][1]);
    }

    public function test_stock_in_history_export_with_no_filter_includes_everything(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'Item A', 'quantity' => 5, 'stock_date' => now()->toDateString()]);
        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'Item B', 'quantity' => 3, 'stock_date' => now()->toDateString()]);

        $this->actingAs($owner);

        $component = Livewire::test('stock-ins.history');
        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);
        $summary = $this->callProtected($component->instance(), 'exportFiltersSummary');

        $this->assertCount(2, $rows);
        $this->assertSame(['All records'], $summary);
    }

    public function test_a_role_without_stock_ins_access_cannot_reach_stock_in_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => []]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('stock-ins.history'))->assertForbidden();
    }

    // ── Udhaar main list ──────────────────────────────────────────────

    public function test_udhaar_index_export_includes_every_customer_on_the_list(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        $customer->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'given', 'amount' => 5000, 'transaction_date' => now()->toDateString()]);

        $component = Livewire::test('udhaar.index');
        $rows = $this->callProtected($component->instance(), 'exportRows', [false]);

        $this->assertCount(1, $rows);
        $this->assertSame('Bilal Khan', $rows[0][0]);
        $this->assertSame('Rs 5,000.00', $rows[0][2]);
    }

    public function test_a_role_without_udhaar_access_cannot_reach_udhaar_index(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => []]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('udhaar.index'))->assertForbidden();
    }

    // ── Udhaar per-customer page ──────────────────────────────────────

    public function test_udhaar_show_export_includes_both_the_ledger_and_other_dues_sections(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        $customer->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString()]);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $component = Livewire::test('udhaar.show', ['customer' => $customer]);
        $sections = $this->callProtected($component->instance(), 'exportSections', [false]);

        $this->assertCount(2, $sections);
        $this->assertSame('Udhaar Ledger', $sections[0]['title']);
        $this->assertCount(1, $sections[0]['rows']);
        $this->assertSame('Other Amounts Owed', $sections[1]['title']);
        $this->assertCount(1, $sections[1]['rows']);
        $this->assertSame('Balance Load', $sections[1]['rows'][0][0]);
    }

    public function test_a_tampered_customer_id_cannot_be_used_to_export_another_shops_udhaar_history(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        $this->actingAs($ownerA);

        $this->get(route('udhaar.show', $customerB))->assertNotFound();
    }

    // ── Party Ledger ──────────────────────────────────────────────────

    public function test_party_ledger_export_includes_earnings_and_per_customer_sections(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);

        $component = Livewire::test('party-ledger.index');
        $sections = $this->callProtected($component->instance(), 'exportSections', [app(ShopReportService::class), false]);

        $this->assertCount(2, $sections);
        $this->assertStringContainsString('Earnings', $sections[0]['title']);
        $this->assertSame(['Metric', 'Amount'], $sections[0]['headers']);
        $this->assertSame('Per-Customer Balances', $sections[1]['title']);

        $customerRow = collect($sections[1]['rows'])->firstWhere(0, 'Bilal Khan');
        $this->assertNotNull($customerRow);
        $this->assertSame('Rs 500.00', $customerRow[1]);
    }

    public function test_a_role_without_party_ledger_access_cannot_reach_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => []]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('party-ledger.index'))->assertForbidden();
    }
}
