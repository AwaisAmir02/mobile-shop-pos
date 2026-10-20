<?php

namespace Tests\Feature;

use App\Actions\CancelRepair;
use App\Actions\UpdateRepair;
use App\Enums\RevisionAction;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RepairEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithRepair(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $repair = Repair::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Screen replacement',
            'amount' => 1000,
            'discount' => 0,
            'total' => 1000,
            'payment_status' => 'paid',
            'amount_paid' => 1000,
        ]);

        return [$shop, $owner, $repair];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        CancelRepair::handle($repair, $owner->id, 'customer changed mind');

        $repair->refresh();
        $this->assertTrue($repair->isCancelled());
        $this->assertNotNull($repair->cancelled_at);
        $this->assertSame($owner->id, $repair->cancelled_by);
        $this->assertSame('customer changed mind', $repair->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_collected_and_owed(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        CancelRepair::handle($repair, $owner->id, null);

        $report = new \App\ReportQueries\RepairReport;
        $this->assertSame(0.0, $report->totalCollected());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        CancelRepair::handle($repair, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelRepair::handle($repair->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        CancelRepair::handle($repair, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', Repair::class)->where('revisionable_id', $repair->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(1000.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        UpdateRepair::handle($repair, [
            'category' => 'mobile',
            'sub_category' => 'Screen',
            'description' => 'Screen replacement (corrected)',
            'service_charges' => 1200,
            'products_charges' => 0,
            'discount' => 100,
            'customer_id' => $customer->id,
        ], $owner->id);

        $repair->refresh();
        $this->assertSame('Screen replacement (corrected)', $repair->description);
        $this->assertSame(1100.0, (float) $repair->total);

        $revision = ModuleRevision::where('revisionable_type', Repair::class)->where('revisionable_id', $repair->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(1000.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_paid(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        $this->expectException(ValidationException::class);
        UpdateRepair::handle($repair, [
            'category' => 'mobile',
            'sub_category' => null,
            'description' => 'x',
            'service_charges' => 500,
            'products_charges' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_editing_requires_a_customer_while_a_due_balance_remains(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $repair->update(['amount_paid' => 400, 'payment_status' => 'partial']);

        $this->expectException(ValidationException::class);
        UpdateRepair::handle($repair, [
            'category' => 'mobile',
            'sub_category' => null,
            'description' => 'x',
            'service_charges' => 1000,
            'products_charges' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_a_cancelled_repair_cannot_be_edited(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        CancelRepair::handle($repair, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateRepair::handle($repair->fresh(), [
            'category' => 'mobile',
            'sub_category' => null,
            'description' => 'x',
            'service_charges' => 1000,
            'products_charges' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        Livewire::test('repairs.history')
            ->call('openEdit', $repair->id)
            ->assertDispatched('open-modal', name: 'edit-repair-form')
            ->call('openCancel', $repair->id)
            ->assertDispatched('open-modal', name: 'cancel-repair-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Technician', 'permissions' => ['repairs']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('repairs.history')->call('openEdit', $repair->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Technician', 'permissions' => ['repairs', 'repairs-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('repairs.history')
            ->set('cancelingId', $repair->id)
            ->set('cancelReason', 'test')
            ->call('cancelRepair');

        $this->assertTrue($repair->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['repairs', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('repairs.history')->call('openCancel', $repair->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_repair(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();
        $repair->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelRepair::handle($repair, $owner->id, null);

        Livewire::test('repairs.history')->call('markAsPaid', $repair->id)->assertStatus(403);
    }

    public function test_repair_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $repairB = Repair::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'category' => 'mobile', 'description' => 'x', 'amount' => 999, 'discount' => 0, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('repairs.history')
                ->set('cancelingId', $repairB->id)
                ->call('cancelRepair');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s repair.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($repairB->fresh()->isCancelled());
    }
}
