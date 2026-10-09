<?php

namespace Tests\Feature;

use App\Actions\CancelBillPayment;
use App\Actions\UpdateBillPayment;
use App\Enums\RevisionAction;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BillPaymentEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithBillPayment(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Main Account', 'provider_type' => 'JazzCash']);
        $payment = BillPayment::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'bill_category_id' => $category->id,
            'bill_provider_id' => $provider->id,
            'shop_account_id' => $account->id,
            'consumer_number' => '123456',
            'consumer_name' => 'Walk-in',
            'amount' => 1000,
            'fee' => 20,
            'discount' => 0,
            'total' => 1020,
            'payment_status' => 'paid',
            'amount_paid' => 1020,
        ]);

        return [$shop, $owner, $payment, $category, $provider, $account];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        CancelBillPayment::handle($payment, $owner->id, 'duplicate entry');

        $payment->refresh();
        $this->assertTrue($payment->isCancelled());
        $this->assertNotNull($payment->cancelled_at);
        $this->assertSame($owner->id, $payment->cancelled_by);
        $this->assertSame('duplicate entry', $payment->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_collected(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        CancelBillPayment::handle($payment, $owner->id, null);

        $report = new \App\ReportQueries\BillReport;
        $this->assertSame(0.0, $report->totalCollected());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        CancelBillPayment::handle($payment, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelBillPayment::handle($payment->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        CancelBillPayment::handle($payment, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', BillPayment::class)->where('revisionable_id', $payment->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(1020.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $payment, $category, $provider, $account] = $this->shopWithBillPayment();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        UpdateBillPayment::handle($payment, [
            'bill_category_id' => $category->id,
            'bill_provider_id' => $provider->id,
            'shop_account_id' => $account->id,
            'consumer_number' => '999999',
            'consumer_name' => 'Ali',
            'amount' => 1200,
            'fee' => 30,
            'discount' => 0,
            'customer_id' => $customer->id,
        ], $owner->id);

        $payment->refresh();
        $this->assertSame('999999', $payment->consumer_number);
        $this->assertSame(1230.0, (float) $payment->total);

        $revision = ModuleRevision::where('revisionable_type', BillPayment::class)->where('revisionable_id', $payment->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(1020.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_paid(): void
    {
        [$shop, $owner, $payment, $category, $provider, $account] = $this->shopWithBillPayment();

        $this->expectException(ValidationException::class);
        UpdateBillPayment::handle($payment, [
            'bill_category_id' => $category->id,
            'bill_provider_id' => $provider->id,
            'shop_account_id' => $account->id,
            'consumer_number' => '123456',
            'consumer_name' => 'Walk-in',
            'amount' => 100,
            'fee' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_a_cancelled_bill_payment_cannot_be_edited(): void
    {
        [$shop, $owner, $payment, $category, $provider, $account] = $this->shopWithBillPayment();
        CancelBillPayment::handle($payment, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateBillPayment::handle($payment->fresh(), [
            'bill_category_id' => $category->id,
            'bill_provider_id' => $provider->id,
            'shop_account_id' => $account->id,
            'consumer_number' => '123456',
            'consumer_name' => 'Walk-in',
            'amount' => 1000,
            'fee' => 20,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        Livewire::test('bills.history')
            ->call('openEdit', $payment->id)
            ->assertDispatched('open-modal', name: 'edit-bill-payment-form')
            ->call('openCancel', $payment->id)
            ->assertDispatched('open-modal', name: 'cancel-bill-payment-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['bills']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('bills.history')->call('openEdit', $payment->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Clerk', 'permissions' => ['bills', 'bills-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('bills.history')
            ->set('cancelingId', $payment->id)
            ->set('cancelReason', 'test')
            ->call('cancelPayment');

        $this->assertTrue($payment->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['bills', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('bills.history')->call('openCancel', $payment->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_bill_payment(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();
        $payment->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelBillPayment::handle($payment, $owner->id, null);

        Livewire::test('bills.history')->call('markAsPaid', $payment->id)->assertStatus(403);
    }

    public function test_bill_payment_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $categoryB = BillCategory::create(['shop_id' => $shopB->id, 'name' => 'Electricity']);
        $providerB = BillProvider::create(['shop_id' => $shopB->id, 'bill_category_id' => $categoryB->id, 'name' => 'LESCO']);
        $accountB = ShopAccount::create(['shop_id' => $shopB->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        $paymentB = BillPayment::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'bill_category_id' => $categoryB->id, 'bill_provider_id' => $providerB->id, 'shop_account_id' => $accountB->id, 'consumer_number' => 'x', 'consumer_name' => 'y', 'amount' => 999, 'fee' => 0, 'discount' => 0, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('bills.history')
                ->set('cancelingId', $paymentB->id)
                ->call('cancelPayment');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s bill payment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($paymentB->fresh()->isCancelled());
    }
}
