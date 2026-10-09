<?php

namespace Tests\Feature;

use App\Actions\CancelSimSale;
use App\Actions\UpdateSimSale;
use App\Enums\RevisionAction;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SimSaleEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithSale(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $sale = SimSale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'network' => 'Jazz',
            'sim_type' => 'prepaid',
            'sim_form' => 'physical',
            'is_duplicate' => false,
            'sim_number' => '03001234567',
            'amount' => 200,
            'fee' => 50,
            'discount' => 0,
            'total' => 250,
            'payment_status' => 'paid',
            'amount_paid' => 250,
        ]);

        return [$shop, $owner, $sale];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        CancelSimSale::handle($sale, $owner->id, 'duplicate entry');

        $sale->refresh();
        $this->assertTrue($sale->isCancelled());
        $this->assertNotNull($sale->cancelled_at);
        $this->assertSame($owner->id, $sale->cancelled_by);
        $this->assertSame('duplicate entry', $sale->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_revenue(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        CancelSimSale::handle($sale, $owner->id, null);

        $report = new \App\ReportQueries\SimSaleReport;
        $this->assertSame(0.0, $report->totalRevenue());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        CancelSimSale::handle($sale, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelSimSale::handle($sale->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        CancelSimSale::handle($sale, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', SimSale::class)->where('revisionable_id', $sale->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(250.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_updates_fields_and_writes_a_revision(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        UpdateSimSale::handle($sale, [
            'network' => 'Zong',
            'sim_type' => 'postpaid',
            'sim_form' => 'esim',
            'is_duplicate' => true,
            'sim_number' => '03119999999',
            'amount' => 300,
            'fee' => 60,
            'discount' => 0,
            'customer_id' => $customer->id,
        ], $owner->id);

        $sale->refresh();
        $this->assertSame('Zong', $sale->network);
        $this->assertSame(360.0, (float) $sale->total);
        $this->assertTrue($sale->is_duplicate);

        $revision = ModuleRevision::where('revisionable_type', SimSale::class)->where('revisionable_id', $sale->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(250.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_rejects_a_total_below_what_is_already_paid(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->expectException(ValidationException::class);
        UpdateSimSale::handle($sale, [
            'network' => 'Jazz',
            'sim_type' => 'prepaid',
            'sim_form' => 'physical',
            'is_duplicate' => false,
            'sim_number' => '03001234567',
            'amount' => 50,
            'fee' => 0,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_a_cancelled_sim_sale_cannot_be_edited(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        CancelSimSale::handle($sale, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateSimSale::handle($sale->fresh(), [
            'network' => 'Jazz',
            'sim_type' => 'prepaid',
            'sim_form' => 'physical',
            'is_duplicate' => false,
            'sim_number' => '03001234567',
            'amount' => 200,
            'fee' => 50,
            'discount' => 0,
            'customer_id' => null,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        Livewire::test('sim-sales.history')
            ->call('openEdit', $sale->id)
            ->assertDispatched('open-modal', name: 'edit-sim-sale-form')
            ->call('openCancel', $sale->id)
            ->assertDispatched('open-modal', name: 'cancel-sim-sale-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['sim-sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('sim-sales.history')->call('openEdit', $sale->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Clerk', 'permissions' => ['sim-sales', 'sim-sales-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('sim-sales.history')
            ->set('cancelingId', $sale->id)
            ->set('cancelReason', 'test')
            ->call('cancelSale');

        $this->assertTrue($sale->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['sim-sales', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('sim-sales.history')->call('openCancel', $sale->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_sim_sale(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $sale->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelSimSale::handle($sale, $owner->id, null);

        Livewire::test('sim-sales.history')->call('markAsPaid', $sale->id)->assertStatus(403);
    }

    public function test_sim_sale_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $saleB = SimSale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'is_duplicate' => false, 'sim_number' => 'x', 'amount' => 999, 'fee' => 0, 'discount' => 0, 'total' => 999, 'payment_status' => 'paid', 'amount_paid' => 999]);

        $this->actingAs($ownerA);

        try {
            Livewire::test('sim-sales.history')
                ->set('cancelingId', $saleB->id)
                ->call('cancelSale');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s SIM sale.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($saleB->fresh()->isCancelled());
    }
}
