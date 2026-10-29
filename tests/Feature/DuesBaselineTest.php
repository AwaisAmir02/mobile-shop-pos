<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\SimSale;
use App\Models\User;
use App\Models\WalletLoad;
use App\ReportQueries\UdhaarReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DuesBaselineTest extends TestCase
{
    use RefreshDatabase;

    public static function seedDuesFixture(TestCase $test): array
    {
        $shop = Shop::create(['name' => 'Dues Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $test->actingAs($owner);

        $bilal = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan', 'phone' => '0300-1112223']);
        $sana = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana', 'phone' => '03214445556']);

        $iphone = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'iPhone 13', 'price' => 50000, 'cost_price' => 40000, 'stock_quantity' => 5, 'details' => ['category' => 'Phone']]);
        $cable = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 300, 'cost_price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $iphone->id)
            ->set('customerId', (string) $bilal->id)
            ->set('amountPaidNow', '45000')
            ->call('completeSale')
            ->assertHasNoErrors();

        Livewire::test('sales.create')
            ->call('addToCart', $cable->id)
            ->set('customerId', (string) $bilal->id)
            ->set('amountPaidNow', '0')
            ->call('completeSale')
            ->assertHasNoErrors();
        CancelSale::handle(Sale::latest('id')->first(), $owner->id, 'Returned');

        Livewire::test('sales.create')
            ->call('addToCart', $cable->id)
            ->set('customerId', (string) $sana->id)
            ->set('amountPaidNow', '0')
            ->call('completeSale')
            ->assertHasNoErrors();

        $bilal->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'given', 'amount' => 3000, 'transaction_date' => now()->toDateString()]);
        $bilal->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'repayment', 'amount' => 1000, 'transaction_date' => now()->toDateString()]);
        $sana->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'repayment', 'amount' => 500, 'transaction_date' => now()->toDateString()]);

        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'category' => 'mobile', 'description' => 'Screen replacement', 'amount' => 2000, 'discount' => 0, 'total' => 2000, 'payment_status' => 'partial', 'amount_paid' => 800]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'category' => 'mobile', 'description' => 'Paid repair', 'amount' => 900, 'discount' => 0, 'total' => 900, 'payment_status' => 'paid', 'amount_paid' => 900]);

        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id, 'shop_account_id' => $account->id, 'consumer_number' => 'x', 'consumer_name' => 'y', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 700, 'total' => 700, 'payment_status' => 'partial', 'amount_paid' => 200]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 5000, 'total' => 5000, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'phone_number' => '03001112223', 'cnic_number' => '42101-1234567-1', 'amount' => 300, 'fee' => 50, 'discount' => 0, 'total' => 350, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $serviceCategory = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $serviceItem = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $serviceCategory->id, 'name' => 'Screen Guard']);
        ServicePayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $bilal->id, 'service_category_id' => $serviceCategory->id, 'service_item_id' => $serviceItem->id, 'amount' => 1000, 'payment_status' => 'partial', 'amount_paid' => 400]);

        return [$shop, $owner, $bilal->fresh(), $sana->fresh()];
    }

    public static function assertGoldenDues(TestCase $test, Customer $bilal, Customer $sana): void
    {
        $test->assertSame(2000.0, $bilal->fresh()->udhaarBalance());
        $test->assertSame(5000.0, $bilal->fresh()->salesOutstandingBalance());
        $test->assertSame(-500.0, $sana->fresh()->udhaarBalance());
        $test->assertSame(300.0, $sana->fresh()->salesOutstandingBalance());

        Livewire::test('udhaar.show', ['customer' => $bilal])
            ->assertViewHas('balance', 11350.0)
            ->assertViewHas('status', 'due')
            ->assertViewHas('dues', fn ($dues) => $dues->count() === 8
                && (float) $dues->sum('amountDue') === 9350.0
                && $dues->pluck('amountDue', 'sourceLabel')->map(fn ($v) => (float) $v)->sortKeys()->all() === [
                    'Balance Load' => 500.0,
                    'Bill' => 1000.0,
                    'NADRA Verification' => 350.0,
                    'Repair' => 1200.0,
                    'SIM Sale' => 200.0,
                    'Sale' => 5000.0,
                    'Service' => 600.0,
                    'Wallet Load' => 500.0,
                ]);

        Livewire::test('udhaar.show', ['customer' => $sana])
            ->assertViewHas('balance', -200.0)
            ->assertViewHas('status', 'advance');

        $rows = (new UdhaarReport)->rows();
        $test->assertSame(11350.0, (float) $rows->firstWhere('customer.id', $bilal->id)['balance']);
        $test->assertSame(-200.0, (float) $rows->firstWhere('customer.id', $sana->id)['balance']);
        $test->assertSame(11350.0, (float) (new UdhaarReport)->totalDue());
    }

    public function test_golden_dues_numbers_across_every_source(): void
    {
        [, , $bilal, $sana] = self::seedDuesFixture($this);

        self::assertGoldenDues($this, $bilal, $sana);
    }
}
