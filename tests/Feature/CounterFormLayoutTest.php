<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The seven counter-service forms share one layout. These
 * pin the layout's parts, not the forms' rules, which their own tests cover.
 */
class CounterFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const SCREENS = [
        'sim-sales.create',
        'balance-loads.create',
        'wallet-loads.create',
        'bills.create',
        'nadra-verifications.create',
        'repairs.create',
        'services.create',
    ];

    /** Screens that ask "Has the customer paid you?" rather than taking cash received. */
    private const STATUS_SCREENS = [
        'sim-sales.create',
        'bills.create',
        'nadra-verifications.create',
        'repairs.create',
        'services.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => true, 'ledger_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
    }

    public function test_every_counter_form_has_the_summary_with_its_total_and_save(): void
    {
        foreach (self::SCREENS as $screen) {
            Livewire::test($screen)
                ->assertSeeHtml('data-counter-total')
                ->assertSeeHtml('wire:submit="save"')
                ->assertSee('Save')
                ->assertDontSee('Optional');
        }
    }

    public function test_payment_status_is_a_segmented_choice_over_the_same_values(): void
    {
        foreach (self::STATUS_SCREENS as $screen) {
            $page = Livewire::test($screen)
                ->assertSeeHtml('type="radio"')
                ->assertSeeHtml('wire:model.live="paymentStatus"')
                ->assertSeeHtml('value="paid"')
                ->assertSeeHtml('value="unpaid"')
                ->assertSeeHtml('value="partial"');

            if ($screen !== 'repairs.create') {
                $page->assertDontSeeHtml('id="amountPaid"')
                    ->set('paymentStatus', 'partial')
                    ->assertSeeHtml('id="amountPaid"');
            }
        }
    }

    public function test_repairs_keeps_amount_paid_for_every_status(): void
    {
        Livewire::test('repairs.create')
            ->set('paymentStatus', 'paid')
            ->assertSeeHtml('wire:model.live="amountPaid"');
    }

    public function test_the_summary_follows_the_entered_amounts(): void
    {
        Livewire::test('balance-loads.create')
            ->set('amount', '500')
            ->set('fee', '20')
            ->set('discount', '5')
            // In-order runs over Livewire's JSON response, where "−" is escaped; check that line on its own.
            ->assertSeeInOrder(['Total Collected', 'Rs 515.00', 'Amount', 'Rs 500.00', 'Service Charge', 'Rs 20.00', 'Discount'])
            ->assertSee('− Rs 5.00')
            ->set('amountReceivedNow', '300')
            ->assertSee('Partial')
            ->assertSee('Rs 215.00');
    }

    public function test_saving_shows_the_receipt_card_with_log_another(): void
    {
        Livewire::test('sim-sales.create')
            ->set('simNumber', '03001234567')
            ->set('amount', '500')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Saved')
            ->assertSee(SimSale::sole()->receiptNumber())
            ->assertSee('Download Receipt')
            ->assertSee('Log Another')
            ->call('logAnother')
            ->assertSeeHtml('data-counter-total');
    }

    public function test_wallet_direction_switch_still_calls_switch_direction(): void
    {
        Livewire::test('wallet-loads.create')
            ->assertSeeHtml("wire:click=\"switchDirection('cash_out')\"")
            ->assertSee('Customer Pays You (cash)')
            ->call('switchDirection', 'cash_out')
            ->assertSee('Customer Sends You (to your account)');
    }

    public function test_bills_send_from_has_a_placeholder_when_the_shop_has_no_accounts(): void
    {
        Livewire::test('bills.create')
            ->assertSet('shopAccountId', '')
            ->assertSeeHtml('<option value="">Select a shop account</option>');
    }
}
