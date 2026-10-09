<?php

namespace Tests\Feature;

use App\Actions\CancelExpense;
use App\Actions\UpdateExpense;
use App\Enums\RevisionAction;
use App\Models\Expense;
use App\Models\ModuleRevision;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithExpense(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $expense = Expense::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'description' => 'Electricity', 'amount' => 500, 'expense_date' => '2026-01-10']);

        return [$shop, $owner, $expense];
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();

        CancelExpense::handle($expense, $owner->id, 'duplicate entry');

        $expense->refresh();
        $this->assertTrue($expense->isCancelled());
        $this->assertNotNull($expense->cancelled_at);
        $this->assertSame($owner->id, $expense->cancelled_by);
        $this->assertSame('duplicate entry', $expense->cancel_reason);
    }

    public function test_cancelling_removes_it_from_the_total_but_keeps_it_visible(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        Expense::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'description' => 'Rent', 'amount' => 1000, 'expense_date' => '2026-01-10']);

        CancelExpense::handle($expense, $owner->id, null);

        $this->assertSame(1000.0, (float) Expense::sum('amount'));
        $this->assertSame(1500.0, (float) Expense::withoutGlobalScope(\App\Models\Scopes\ExcludesCancelledScope::class)->sum('amount'));
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();

        CancelExpense::handle($expense, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelExpense::handle($expense->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();

        CancelExpense::handle($expense, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', Expense::class)->where('revisionable_id', $expense->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame('oops', $revision->reason);
        $this->assertSame(500.0, (float) $revision->snapshot['amount']);
    }

    public function test_editing_updates_fields_and_writes_a_revision_with_the_old_values(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();

        UpdateExpense::handle($expense, 'Electricity bill (corrected)', 650.0, '2026-01-12', $owner->id);

        $expense->refresh();
        $this->assertSame('Electricity bill (corrected)', $expense->description);
        $this->assertSame(650.0, (float) $expense->amount);
        $this->assertSame('2026-01-12', $expense->expense_date->toDateString());

        $revision = ModuleRevision::where('revisionable_type', Expense::class)->where('revisionable_id', $expense->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame('Electricity', $revision->snapshot['description']);
        $this->assertSame(500.0, (float) $revision->snapshot['amount']);
    }

    public function test_a_cancelled_expense_cannot_be_edited(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        CancelExpense::handle($expense, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateExpense::handle($expense->fresh(), 'New description', 100.0, '2026-01-10', $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        $this->actingAs($owner);

        Livewire::test('expenses.index')
            ->call('openEdit', $expense->id)
            ->assertDispatched('open-modal', name: 'edit-expense-form')
            ->call('openCancel', $expense->id)
            ->assertDispatched('open-modal', name: 'cancel-expense-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['expenses']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('expenses.index')->call('openEdit', $expense->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Cashier', 'permissions' => ['expenses', 'expenses-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('expenses.index')
            ->set('cancelingId', $expense->id)
            ->set('cancelReason', 'test')
            ->call('cancelExpense');

        $this->assertTrue($expense->fresh()->isCancelled());
    }

    /**
     * Pre-existing Roles simply have a permissions array that never
     * mentions 'expenses-edit-cancel' — there is no backfill step, matching
     * the same precedent established for Vendors and Sales.
     */
    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $expense] = $this->shopWithExpense();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['expenses', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('expenses.index')->call('openCancel', $expense->id)->assertStatus(403);
    }

    public function test_expense_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $expenseB = Expense::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'description' => 'Other shop', 'amount' => 999, 'expense_date' => '2026-01-10']);

        $this->actingAs($ownerA);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('expenses.index')->call('openEdit', $expenseB->id);
    }
}
