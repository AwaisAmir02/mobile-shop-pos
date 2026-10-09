<?php

namespace Tests\Feature;

use App\Actions\CancelNadraVerification;
use App\Actions\UpdateNadraVerification;
use App\Enums\RevisionAction;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\NadraVerification;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class NadraVerificationEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithVerification(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $verification = NadraVerification::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'phone_number' => '0300-1234567',
            'cnic_number' => '12345-1234567-1',
            'amount' => 500,
            'fee' => 50,
            'discount' => 0,
            'total' => 550,
            'payment_status' => 'paid',
            'amount_paid' => 550,
        ]);

        return [$shop, $owner, $verification];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        CancelNadraVerification::handle($verification, $owner->id, 'duplicate entry');

        $verification->refresh();
        $this->assertTrue($verification->isCancelled());
        $this->assertNotNull($verification->cancelled_at);
        $this->assertSame($owner->id, $verification->cancelled_by);
        $this->assertSame('duplicate entry', $verification->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_collected(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        CancelNadraVerification::handle($verification, $owner->id, null);

        $report = new \App\ReportQueries\NadraVerificationReport;
        $this->assertSame(0.0, $report->totalCollected());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        CancelNadraVerification::handle($verification, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelNadraVerification::handle($verification->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        CancelNadraVerification::handle($verification, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', NadraVerification::class)->where('revisionable_id', $verification->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(550.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        UpdateNadraVerification::handle($verification, [
            'phone_number' => '0300-9999999',
            'cnic_number' => '12345-1234567-1',
            'amount' => 600,
            'fee' => 60,
            'discount' => 0,
            'customer_id' => $customer->id,
        ], $owner->id);

        $verification->refresh();
        $this->assertSame('0300-9999999', $verification->phone_number);
        $this->assertSame(660.0, (float) $verification->total);

        $revision = ModuleRevision::where('revisionable_type', NadraVerification::class)->where('revisionable_id', $verification->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(550.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_paid(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        $this->expectException(ValidationException::class);
        UpdateNadraVerification::handle($verification, [
            'phone_number' => '0300-1234567',
            'cnic_number' => '12345-1234567-1',
            'amount' => 100,
            'fee' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_editing_requires_a_customer_while_a_due_balance_remains(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $verification->update(['amount_paid' => 200, 'payment_status' => 'partial']);

        $this->expectException(ValidationException::class);
        UpdateNadraVerification::handle($verification, [
            'phone_number' => '0300-1234567',
            'cnic_number' => '12345-1234567-1',
            'amount' => 500,
            'fee' => 50,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_a_cancelled_verification_cannot_be_edited(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        CancelNadraVerification::handle($verification, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateNadraVerification::handle($verification->fresh(), [
            'phone_number' => '0300-1234567',
            'cnic_number' => '12345-1234567-1',
            'amount' => 500,
            'fee' => 50,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();

        Livewire::test('nadra-verifications.history')
            ->call('openEdit', $verification->id)
            ->assertDispatched('open-modal', name: 'edit-verification-form')
            ->call('openCancel', $verification->id)
            ->assertDispatched('open-modal', name: 'cancel-verification-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Agent', 'permissions' => ['nadra-verifications']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('nadra-verifications.history')->call('openEdit', $verification->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Agent', 'permissions' => ['nadra-verifications', 'nadra-verifications-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('nadra-verifications.history')
            ->set('cancelingId', $verification->id)
            ->set('cancelReason', 'test')
            ->call('cancelVerification');

        $this->assertTrue($verification->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['nadra-verifications', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('nadra-verifications.history')->call('openCancel', $verification->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_verification(): void
    {
        [$shop, $owner, $verification] = $this->shopWithVerification();
        $verification->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelNadraVerification::handle($verification, $owner->id, null);

        Livewire::test('nadra-verifications.history')->call('markAsPaid', $verification->id)->assertStatus(403);
    }

    public function test_nadra_verification_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $verificationB = NadraVerification::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'phone_number' => 'x', 'cnic_number' => 'y', 'amount' => 999, 'fee' => 0, 'discount' => 0, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('nadra-verifications.history')
                ->set('cancelingId', $verificationB->id)
                ->call('cancelVerification');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s verification.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($verificationB->fresh()->isCancelled());
    }
}
