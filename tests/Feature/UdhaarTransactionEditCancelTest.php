<?php

namespace Tests\Feature;

use App\Actions\CancelUdhaarTransaction;
use App\Actions\CreateUdhaarTransaction;
use App\Enums\RevisionAction;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UdhaarTransactionEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithCustomer(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $this->actingAs($owner);

        return [$shop, $owner, $customer];
    }

    public function test_editing_a_transaction_updates_its_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01', 'Original note');

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openEdit', $transaction->id)
            ->set('editType', 'given')
            ->set('editAmount', '1200')
            ->set('editTransactionDate', '2026-08-02')
            ->set('editNote', 'Corrected amount')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $transaction->refresh();
        $this->assertEquals(1200, $transaction->amount);
        $this->assertSame('2026-08-02', $transaction->transaction_date->toDateString());
        $this->assertSame('Corrected amount', $transaction->note);

        $revision = ModuleRevision::where('revisionable_type', UdhaarTransaction::class)->where('revisionable_id', $transaction->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(1000.0, (float) $revision->snapshot['amount']);
    }

    public function test_editing_an_earlier_transaction_correctly_recalculates_every_later_running_balance(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $first = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');
        CreateUdhaarTransaction::handle($customer, 'given', '500', '2026-08-05');

        $this->assertSame(1500.0, $customer->fresh()->udhaarBalance());

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openEdit', $first->id)
            ->set('editType', 'given')
            ->set('editAmount', '2000')
            ->set('editTransactionDate', '2026-08-01')
            ->set('editNote', '')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame(2500.0, $customer->fresh()->udhaarBalance());
    }

    public function test_cancelling_a_transaction_removes_its_effect_from_the_balance_but_keeps_it_visible(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');
        CreateUdhaarTransaction::handle($customer, 'given', '500', '2026-08-05');

        $this->assertSame(1500.0, $customer->fresh()->udhaarBalance());

        $component = Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openCancel', $transaction->id)
            ->set('cancelReason', 'Entered by mistake')
            ->call('cancelTransaction');

        $this->assertSame(500.0, $customer->fresh()->udhaarBalance());
        $this->assertTrue($transaction->fresh()->isCancelled());

        $component->assertSee('Cancelled');

        $revision = ModuleRevision::where('revisionable_type', UdhaarTransaction::class)->where('revisionable_id', $transaction->id)->first();
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame('Entered by mistake', $revision->reason);
    }

    public function test_a_reversed_transaction_cannot_be_edited_or_cancelled(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');

        Livewire::test('udhaar.show', ['customer' => $customer])->call('reverse', $transaction->id);

        $this->assertTrue($transaction->fresh()->isReversed());

        Livewire::test('udhaar.show', ['customer' => $customer])->call('openEdit', $transaction->id)->assertStatus(403);
        Livewire::test('udhaar.show', ['customer' => $customer])->call('openCancel', $transaction->id)->assertStatus(403);
    }

    public function test_a_cancelled_transaction_cannot_be_reversed_edited_or_cancelled_again(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');
        CancelUdhaarTransaction::handle($transaction, $owner->id, null);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('reverse', $transaction->id)
            ->assertDispatched('toast', message: 'A cancelled transaction cannot be reversed.', type: 'error');

        $this->assertFalse($transaction->fresh()->isReversed());

        Livewire::test('udhaar.show', ['customer' => $customer])->call('openEdit', $transaction->id)->assertStatus(403);
        Livewire::test('udhaar.show', ['customer' => $customer])->call('openCancel', $transaction->id)->assertStatus(403);
    }

    public function test_a_reversal_entry_cannot_be_edited_or_cancelled(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');

        Livewire::test('udhaar.show', ['customer' => $customer])->call('reverse', $transaction->id);
        $reversal = UdhaarTransaction::where('reverses_transaction_id', $transaction->id)->firstOrFail();

        Livewire::test('udhaar.show', ['customer' => $customer])->call('openEdit', $reversal->id)->assertStatus(403);
        Livewire::test('udhaar.show', ['customer' => $customer])->call('openCancel', $reversal->id)->assertStatus(403);
    }

    public function test_editing_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Customer A']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B']);
        $transactionB = UdhaarTransaction::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-08-01']);
        $this->actingAs($ownerA);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('udhaar.show', ['customer' => $customerA])->call('openEdit', $transactionB->id);
    }

    public function test_cancelling_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Customer A']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B']);
        $transactionB = UdhaarTransaction::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-08-01']);
        $this->actingAs($ownerA);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('udhaar.show', ['customer' => $customerA])->call('openCancel', $transactionB->id);
    }

    public function test_the_edit_and_cancel_buttons_are_inside_their_own_component_boundary(): void
    {
        [$shop, $owner, $customer] = $this->shopWithCustomer();
        $transaction = CreateUdhaarTransaction::handle($customer, 'given', '1000', '2026-08-01');

        $html = $this->get(route('udhaar.show', $customer))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        $componentSnapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);

            if (($snapshot['memo']['name'] ?? null) === 'udhaar.show') {
                $componentSnapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($componentSnapshotOffset, 'Could not find a wire:snapshot for [udhaar.show].');

        foreach (["wire:click=\"openEdit({$transaction->id})\"", "wire:click=\"openCancel({$transaction->id})\""] as $needle) {
            $needleOffset = strpos($html, $needle);
            $this->assertNotFalse($needleOffset, "Markup \"{$needle}\" not found on the page.");
            $this->assertGreaterThan($componentSnapshotOffset, $needleOffset, "\"{$needle}\" is outside the component's wire:id boundary.");
        }
    }
}
