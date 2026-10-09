<?php

namespace Tests\Feature;

use App\Actions\CancelBalanceLoad;
use App\Actions\UpdateBalanceLoad;
use App\Enums\RevisionAction;
use App\Models\BalanceLoad;
use App\Models\ModuleRevision;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceLoadEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithLoad(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $load = BalanceLoad::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'network' => 'Jazz',
            'load_type' => 'balance',
            'phone_number' => '0300-0000000',
            'amount' => 500,
            'fee' => 10,
            'discount' => 0,
            'total' => 510,
            'payment_status' => 'paid',
            'amount_paid' => 510,
        ]);

        return [$shop, $owner, $load];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelBalanceLoad::handle($load, $owner->id, 'duplicate entry');

        $load->refresh();
        $this->assertTrue($load->isCancelled());
        $this->assertNotNull($load->cancelled_at);
        $this->assertSame($owner->id, $load->cancelled_by);
        $this->assertSame('duplicate entry', $load->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_loaded(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelBalanceLoad::handle($load, $owner->id, null);

        $report = new \App\ReportQueries\BalanceLoadReport;
        $this->assertSame(0.0, $report->totalLoaded());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelBalanceLoad::handle($load, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelBalanceLoad::handle($load->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        CancelBalanceLoad::handle($load, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', BalanceLoad::class)->where('revisionable_id', $load->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(510.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        UpdateBalanceLoad::handle($load, [
            'network' => 'Zong',
            'load_type' => 'package',
            'phone_number' => '0310-1111111',
            'amount' => 600,
            'fee' => 20,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);

        $load->refresh();
        $this->assertSame('Zong', $load->network);
        $this->assertSame(620.0, (float) $load->total);

        $revision = ModuleRevision::where('revisionable_type', BalanceLoad::class)->where('revisionable_id', $load->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(510.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_recorded(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->expectException(ValidationException::class);
        UpdateBalanceLoad::handle($load, [
            'network' => 'Jazz',
            'load_type' => 'balance',
            'phone_number' => null,
            'amount' => 100,
            'fee' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_a_cancelled_balance_load_cannot_be_edited(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        CancelBalanceLoad::handle($load, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateBalanceLoad::handle($load->fresh(), [
            'network' => 'Jazz',
            'load_type' => 'balance',
            'phone_number' => null,
            'amount' => 500,
            'fee' => 10,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        Livewire::test('balance-loads.history')
            ->call('openEdit', $load->id)
            ->assertDispatched('open-modal', name: 'edit-balance-load-form')
            ->call('openCancel', $load->id)
            ->assertDispatched('open-modal', name: 'cancel-balance-load-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['balance-loads']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('balance-loads.history')->call('openEdit', $load->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Clerk', 'permissions' => ['balance-loads', 'balance-loads-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('balance-loads.history')
            ->set('cancelingId', $load->id)
            ->set('cancelReason', 'test')
            ->call('cancelLoad');

        $this->assertTrue($load->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['balance-loads', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('balance-loads.history')->call('openCancel', $load->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_balance_load(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();
        $load->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelBalanceLoad::handle($load, $owner->id, null);

        Livewire::test('balance-loads.history')->call('markAsPaid', $load->id)->assertStatus(403);
    }

    public function test_balance_load_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $loadB = BalanceLoad::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 999, 'fee' => 0, 'discount' => 0, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('balance-loads.history')
                ->set('cancelingId', $loadB->id)
                ->call('cancelLoad');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s balance load.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($loadB->fresh()->isCancelled());
    }
}
