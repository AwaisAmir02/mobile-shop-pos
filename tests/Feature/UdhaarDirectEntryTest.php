<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UdhaarDirectEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transaction_created_directly_from_the_udhaar_screen_matches_one_created_via_the_customer_profile(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $customerViaProfile = Customer::create(['shop_id' => $shop->id, 'name' => 'Via Profile']);
        $customerViaUdhaar = Customer::create(['shop_id' => $shop->id, 'name' => 'Via Udhaar Screen']);

        $this->actingAs($owner);

        // Path 1: existing per-customer profile entry point.
        Livewire::test('udhaar.show', ['customer' => $customerViaProfile])
            ->set('type', 'given')
            ->set('amount', '1500')
            ->set('transaction_date', '2026-08-01')
            ->set('note', 'Via profile')
            ->call('save')
            ->assertHasNoErrors();

        // Path 2: new direct entry point on the Udhaar list screen.
        Livewire::test('udhaar.index')
            ->set('customerId', (string) $customerViaUdhaar->id)
            ->set('type', 'given')
            ->set('amount', '1500')
            ->set('transaction_date', '2026-08-01')
            ->set('note', 'Via profile')
            ->call('save')
            ->assertHasNoErrors();

        $transactionA = UdhaarTransaction::where('customer_id', $customerViaProfile->id)->firstOrFail();
        $transactionB = UdhaarTransaction::where('customer_id', $customerViaUdhaar->id)->firstOrFail();

        $this->assertSame($transactionA->type->value, $transactionB->type->value);
        $this->assertEquals($transactionA->amount, $transactionB->amount);
        $this->assertSame($transactionA->transaction_date->toDateString(), $transactionB->transaction_date->toDateString());
        $this->assertSame($transactionA->note, $transactionB->note);
        $this->assertSame($transactionA->user_id, $transactionB->user_id);
        $this->assertSame($shop->id, $transactionB->shop_id);

        $this->assertSame(1500.0, $customerViaProfile->fresh()->udhaarBalance());
        $this->assertSame(1500.0, $customerViaUdhaar->fresh()->udhaarBalance());
    }

    public function test_the_udhaar_screens_add_transaction_button_is_present_and_a_tampered_customer_id_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        $this->actingAs($ownerA);

        Livewire::test('udhaar.index')
            ->assertSee('Add Transaction')
            ->set('customerId', (string) $customerB->id)
            ->set('type', 'given')
            ->set('amount', '500')
            ->set('transaction_date', '2026-08-01')
            ->call('save')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, UdhaarTransaction::count());
    }

    public function test_selecting_new_customer_from_the_udhaar_screen_opens_the_shared_quick_create_component(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('udhaar.index')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    public function test_the_add_transaction_button_is_rendered_inside_the_livewire_component_not_the_static_header_slot(): void
    {
        // Livewire::test() renders the component in isolation and can't
        // catch a button that is visibly present but wired to nothing —
        // that only happens when markup sits outside the Livewire
        // component's own root element (e.g. accidentally placed in the
        // layout's static x-slot="header" wrapper instead of the body),
        // so wire:click never binds and clicking silently does nothing.
        // A real HTTP request through the full layout is required to
        // catch that class of bug: the button's wire:click attribute
        // must appear after the component's own wire:snapshot root, not
        // before it in the page's static header bar.
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $html = $this->get(route('udhaar.index'))->getContent();

        $componentRootPos = strpos($html, 'udhaar.index');
        $buttonPos = strpos($html, 'wire:click="openAddTransaction"');

        $this->assertNotFalse($componentRootPos, 'Could not locate the udhaar.index component root in the rendered page.');
        $this->assertNotFalse($buttonPos, 'Could not locate the Add Transaction button in the rendered page.');
        $this->assertGreaterThan(
            $componentRootPos,
            $buttonPos,
            'The Add Transaction button must render inside the Livewire component root, not in the static header slot before it.'
        );
    }
}
