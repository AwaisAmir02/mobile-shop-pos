<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\UdhaarReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Customers and Vendors lists show a balance, and the
 * customer and Udhaar pages show how their figures add up. Every figure is one
 * an existing screen already calculates; these pin that they are reused, not
 * recalculated.
 */
class PartyBalanceDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id]));
    }

    /** Ali owes 300 udhaar plus an unpaid 1,000 sale: 1,300 on the Udhaar list. */
    private function aliWithDues(): Customer
    {
        $ali = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ali Khan']);
        UdhaarTransaction::create(['shop_id' => $this->shop->id, 'customer_id' => $ali->id, 'type' => 'given', 'amount' => 300, 'transaction_date' => now()->toDateString()]);
        Sale::create(['shop_id' => $this->shop->id, 'customer_id' => $ali->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);

        return $ali;
    }

    public function test_the_customers_list_balance_is_the_udhaar_lists_own_figure(): void
    {
        $ali = $this->aliWithDues();
        Customer::create(['shop_id' => $this->shop->id, 'name' => 'Sana Malik']);

        $udhaarFigure = (new UdhaarReport)->rows()->firstWhere('customer.id', $ali->id)['balance'];
        $this->assertEquals(1300, $udhaarFigure);

        Livewire::test('customers.index')
            ->assertSeeInOrder(['Ali Khan', 'Rs 1,300.00', 'Due', 'Sana Malik', 'Settled']);
    }

    public function test_the_customer_profile_shows_the_same_total_and_its_parts(): void
    {
        $ali = $this->aliWithDues();

        Livewire::test('customers.show', ['customer' => $ali])
            ->assertViewHas('totalOwed', fn ($value) => (float) $value === 1300.0)
            ->assertSeeInOrder(['Total Owed', 'Rs 1,300.00', 'Sales Outstanding', 'Rs 1,000.00', 'Udhaar Loans', 'Rs 300.00']);
    }

    public function test_the_udhaar_page_splits_its_headline_into_loans_and_other_dues(): void
    {
        $ali = $this->aliWithDues();

        Livewire::test('udhaar.show', ['customer' => $ali])
            ->assertViewHas('balance', fn ($value) => (float) $value === 1300.0)
            ->assertSeeInOrder(['Current Balance', 'Rs 1,300.00', 'Udhaar Loans', 'Rs 300.00', 'Unpaid Sales', 'Rs 1,000.00']);
    }

    public function test_the_vendors_list_owed_is_the_vendor_pages_figure(): void
    {
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Acme Distributors']);
        StockIn::create(['shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 5, 'total_cost' => 500, 'payment_status' => 'paid', 'amount_paid' => 500, 'stock_date' => now()->toDateString()]);
        StockIn::create(['shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Charger', 'quantity' => 3, 'total_cost' => 900, 'payment_status' => 'partial', 'amount_paid' => 300, 'stock_date' => now()->toDateString()]);
        Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Zed Traders']);

        $this->assertEquals(600, $vendor->totalOwed());

        Livewire::test('vendors.index')
            ->assertSeeInOrder(['Acme Distributors', 'Rs 600.00', 'Owed', 'Zed Traders', 'Settled']);
    }

    public function test_the_vendors_list_does_not_query_once_per_vendor(): void
    {
        $count = function (int $vendors): int {
            DB::table('vendors')->delete();
            foreach (range(1, $vendors) as $i) {
                $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => "Vendor {$i}"]);
                StockIn::create(['shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 1, 'total_cost' => 100, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => now()->toDateString()]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test('vendors.index');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(6));
    }

    public function test_person_tabs_link_their_screens_and_follow_permissions(): void
    {
        $ali = $this->aliWithDues();

        Livewire::test('customers.show', ['customer' => $ali])
            ->assertSeeHtml('data-party-tabs')
            ->assertSeeHtml(route('udhaar.show', $ali))
            ->assertSeeHtml(e(route('party-ledger.index', ['party' => 'customer', 'customer' => $ali->id])));

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Counter', 'permissions' => ['customers']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]));

        // Only the profile is open to them, and one tab is no navigation.
        Livewire::test('customers.show', ['customer' => $ali])
            ->assertDontSeeHtml('data-party-tabs')
            ->assertDontSeeHtml(route('udhaar.show', $ali))
            ->assertDontSee('Open their Udhaar');
    }
}
