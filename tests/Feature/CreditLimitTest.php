<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Models\Sale;
use App\Models\User;
use App\Support\CreditControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Credit limits and payment terms (W5, CreditControl): the due date a credit sale
 * gets, and a sale over the limit refused until the owner approves it.
 */
class CreditLimitTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_terms_set_the_due_date_and_a_typed_due_date_wins(): void
    {
        $product = $this->product();
        $customer = $this->customer(['payment_terms_days' => 30]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2026-12-20', Sale::latest('id')->first()->due_date->toDateString());

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('amountPaidNow', '0')->set('dueDate', '2026-11-27')->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2026-11-27', Sale::latest('id')->first()->due_date->toDateString());

        // Paid in full: no due date.
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('completeSale')->assertHasNoErrors();
        $this->assertNull(Sale::latest('id')->first()->due_date);
    }

    public function test_the_credit_limit_stops_a_cashier_until_the_owner_approves(): void
    {
        $product = $this->product();
        $customer = $this->customer(['credit_limit' => 1000]);
        $this->sell($customer, $product, 9, 0);
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false]);
        $this->actingAs($staff);

        // 9 at the normal Rs 100 (straight through CreateSale) = 900 owed; 3 more at the trade Rs 90 = 270.
        $this->assertSame(['limit' => 1000.0, 'owed' => 900.0, 'after' => 1170.0, 'available' => 100.0, 'over' => true], CreditControl::check($customer, 270));

        $register = fn () => Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 3)->set('amountPaidNow', '0');

        $register()->assertSee('over the credit limit')->call('completeSale')->assertHasErrors('credit');
        $register()->set('overrideCredit', true)->set('overrideReason', 'Regular, pays monthly')->set('ownerPassword', 'wrong')->call('completeSale')->assertHasErrors('ownerPassword');
        $this->assertSame(1, Sale::count());

        $register()->set('overrideCredit', true)->set('overrideReason', 'Regular, pays monthly')->set('ownerPassword', 'owner-secret')->call('completeSale')->assertHasNoErrors();
        $sale = Sale::latest('id')->first();
        $this->assertSame([$this->owner->id, 'Regular, pays monthly'], [$sale->credit_override_by, $sale->credit_override_reason]);

        // Within the limit, or paid now: no question asked.
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('completeSale')->assertHasNoErrors();
    }

    public function test_the_owner_approves_without_a_password(): void
    {
        $product = $this->product();
        $customer = $this->customer(['credit_limit' => 100]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 2)->set('amountPaidNow', '0')
            ->call('completeSale')->assertHasErrors('credit')
            ->set('overrideCredit', true)->set('overrideReason', 'Known customer')->call('completeSale')->assertHasNoErrors();

        $this->assertSame($this->owner->id, Sale::sole()->credit_override_by);
    }
}
