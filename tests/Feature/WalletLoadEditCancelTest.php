<?php

namespace Tests\Feature;

use App\Actions\CancelWalletLoad;
use App\Actions\UpdateWalletLoad;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithLoad(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        $load = WalletLoad::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'direction' => 'cash_in',
            'provider' => 'JazzCash',
            'account_name' => 'Ali',
            'account_number' => '0300-0000000',
            'shop_account_id' => $account->id,
            'amount' => 1000,
            'fee' => 20,
            'discount' => 0,
            'fee_included_in_amount' => false,
            'net_amount' => 1000,
            'total' => 1020,
            'payment_status' => 'paid',
            'amount_paid' => 1020,
        ]);

        return [$shop, $owner, $load, $account];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelWalletLoad::handle($load, $owner->id, 'duplicate entry');

        $load->refresh();
        $this->assertTrue($load->isCancelled());
        $this->assertNotNull($load->cancelled_at);
        $this->assertSame($owner->id, $load->cancelled_by);
        $this->assertSame('duplicate entry', $load->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_loaded(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelWalletLoad::handle($load, $owner->id, null);

        $report = new \App\ReportQueries\WalletLoadReport('cash_in');
        $this->assertSame(0.0, $report->totalLoaded());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelWalletLoad::handle($load, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelWalletLoad::handle($load->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelWalletLoad::handle($load, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', WalletLoad::class)->where('revisionable_id', $load->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(1020.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $load, $account] = $this->shopWithLoad();

        UpdateWalletLoad::handle($load, [
            'shop_account_id' => $account->id,
            'customer_id' => null,
            'account_name' => 'Ali Khan',
            'account_number' => '0300-1111111',
            'amount' => 1200,
            'fee' => 30,
            'discount' => 0,
            'fee_included_in_amount' => false,
        ], $owner->id);

        $load->refresh();
        $this->assertSame('Ali Khan', $load->account_name);
        $this->assertSame(1230.0, (float) $load->total);

        $revision = ModuleRevision::where('revisionable_type', WalletLoad::class)->where('revisionable_id', $load->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(1020.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_recorded(): void
    {
        [$shop, $owner, $load, $account] = $this->shopWithLoad();

        $this->expectException(ValidationException::class);
        UpdateWalletLoad::handle($load, [
            'shop_account_id' => $account->id,
            'customer_id' => null,
            'account_name' => 'Ali',
            'account_number' => '0300-0000000',
            'amount' => 100,
            'fee' => 0,
            'discount' => 0,
            'fee_included_in_amount' => false,
        ], $owner->id);
    }

    public function test_a_cancelled_wallet_load_cannot_be_edited(): void
    {
        [$shop, $owner, $load, $account] = $this->shopWithLoad();
        CancelWalletLoad::handle($load, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateWalletLoad::handle($load->fresh(), [
            'shop_account_id' => $account->id,
            'customer_id' => null,
            'account_name' => 'Ali',
            'account_number' => '0300-0000000',
            'amount' => 1000,
            'fee' => 20,
            'discount' => 0,
            'fee_included_in_amount' => false,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        Livewire::test('wallet-loads.history')
            ->call('openEdit', $load->id)
            ->assertDispatched('open-modal', name: 'edit-wallet-load-form')
            ->call('openCancel', $load->id)
            ->assertDispatched('open-modal', name: 'cancel-wallet-load-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['wallet-loads']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('wallet-loads.history')->call('openEdit', $load->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Clerk', 'permissions' => ['wallet-loads', 'wallet-loads-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('wallet-loads.history')
            ->set('cancelingId', $load->id)
            ->set('cancelReason', 'test')
            ->call('cancelLoad');

        $this->assertTrue($load->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['wallet-loads', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('wallet-loads.history')->call('openCancel', $load->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_wallet_load(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $load->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelWalletLoad::handle($load, $owner->id, null);

        Livewire::test('wallet-loads.history')->call('markAsPaid', $load->id)->assertStatus(403);
    }

    public function test_wallet_load_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $accountB = ShopAccount::create(['shop_id' => $shopB->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        $loadB = WalletLoad::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'account_name' => 'x', 'account_number' => 'y', 'shop_account_id' => $accountB->id, 'amount' => 999, 'fee' => 0, 'discount' => 0, 'fee_included_in_amount' => false, 'net_amount' => 999, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('wallet-loads.history')
                ->set('cancelingId', $loadB->id)
                ->call('cancelLoad');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s wallet load.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($loadB->fresh()->isCancelled());
    }
}
