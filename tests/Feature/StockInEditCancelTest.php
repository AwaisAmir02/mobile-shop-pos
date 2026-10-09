<?php

namespace Tests\Feature;

use App\Actions\CancelStockIn;
use App\Actions\UpdateStockIn;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StockInEditCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithStockIn(int $stock = 10, int $quantity = 5, ?int $quantityRemaining = null): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'cost_price' => 50, 'stock_quantity' => $stock, 'details' => ['category' => 'Cable']]);
        $entry = StockIn::create([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'user_id' => $owner->id,
            'quantity' => $quantity,
            'cost_price' => 50,
            'selling_price' => 100,
            'quantity_remaining' => $quantityRemaining ?? $quantity,
            'total_cost' => 50 * $quantity,
            'payment_status' => 'paid',
            'amount_paid' => 50 * $quantity,
            'stock_date' => '2026-01-10',
        ]);

        return [$shop, $owner, $product, $entry];
    }

    public function test_cancelling_reduces_stock_by_the_entrys_quantity(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 10, quantity: 5);

        $result = CancelStockIn::handle($entry, $owner->id, 'wrong entry');

        $this->assertTrue($result['reversed']);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();

        CancelStockIn::handle($entry, $owner->id, 'duplicate entry');

        $entry->refresh();
        $this->assertTrue($entry->isCancelled());
        $this->assertNotNull($entry->cancelled_at);
        $this->assertSame($owner->id, $entry->cancelled_by);
        $this->assertSame('duplicate entry', $entry->cancel_reason);
    }

    public function test_cancelling_is_blocked_when_stock_already_sold_below_the_entrys_quantity(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 3, quantity: 5, quantityRemaining: 2);

        $this->expectException(ValidationException::class);
        CancelStockIn::handle($entry, $owner->id, null);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertFalse($entry->fresh()->isCancelled());
    }

    public function test_cancelling_skips_reversal_when_the_product_was_deleted(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        $product->delete();
        $entry->refresh();

        $result = CancelStockIn::handle($entry, $owner->id, null);

        $this->assertFalse($result['reversed']);
        $this->assertTrue($entry->fresh()->isCancelled());
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();

        CancelStockIn::handle($entry, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelStockIn::handle($entry->fresh(), $owner->id, null);
    }

    public function test_cancelling_writes_a_module_revision_with_a_snapshot(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();

        CancelStockIn::handle($entry, $owner->id, 'oops');

        $revision = ModuleRevision::where('revisionable_type', StockIn::class)->where('revisionable_id', $entry->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(RevisionAction::Cancelled, $revision->action);
        $this->assertSame(5, $revision->snapshot['quantity']);
    }

    public function test_editing_increases_quantity_and_increments_stock(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 10, quantity: 5);

        UpdateStockIn::handle($entry, [
            'quantity' => 8,
            'vendor_id' => null,
            'stock_date' => '2026-01-11',
            'note' => 'corrected',
            'cost_price' => 50,
            'selling_price' => 100,
        ], $owner->id);

        $entry->refresh();
        $this->assertSame(8, $entry->quantity);
        $this->assertSame(13, $product->fresh()->stock_quantity);
        $this->assertSame(400.0, (float) $entry->total_cost);
    }

    public function test_editing_decreases_quantity_and_decrements_stock(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 10, quantity: 5);
        $entry->update(['amount_paid' => 0, 'payment_status' => 'unpaid']);

        UpdateStockIn::handle($entry, [
            'quantity' => 2,
            'vendor_id' => null,
            'stock_date' => '2026-01-11',
            'note' => null,
            'cost_price' => 50,
            'selling_price' => 100,
        ], $owner->id);

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_editing_down_is_blocked_when_stock_already_sold_below_the_reduction(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 3, quantity: 5, quantityRemaining: 2);

        $this->expectException(ValidationException::class);
        UpdateStockIn::handle($entry, [
            'quantity' => 1,
            'vendor_id' => null,
            'stock_date' => '2026-01-11',
            'note' => null,
            'cost_price' => 50,
            'selling_price' => 100,
        ], $owner->id);
    }

    public function test_editing_writes_a_revision_with_the_old_values(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn(stock: 10, quantity: 5);

        UpdateStockIn::handle($entry, [
            'quantity' => 6,
            'vendor_id' => null,
            'stock_date' => '2026-01-12',
            'note' => null,
            'cost_price' => 50,
            'selling_price' => 100,
        ], $owner->id);

        $revision = ModuleRevision::where('revisionable_type', StockIn::class)->where('revisionable_id', $entry->id)->first();
        $this->assertSame(RevisionAction::Edited, $revision->action);
        $this->assertSame(5, $revision->snapshot['quantity']);
        $this->assertSame('2026-01-10', $revision->snapshot['stock_date']);
    }

    public function test_a_cancelled_stock_in_entry_cannot_be_edited(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        CancelStockIn::handle($entry, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateStockIn::handle($entry->fresh(), [
            'quantity' => 5,
            'vendor_id' => null,
            'stock_date' => '2026-01-10',
            'note' => null,
            'cost_price' => 50,
            'selling_price' => 100,
        ], $owner->id);
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();

        Livewire::test('stock-ins.history')
            ->call('openEdit', $entry->id)
            ->assertDispatched('open-modal', name: 'edit-stock-in-form')
            ->call('openCancel', $entry->id)
            ->assertDispatched('open-modal', name: 'cancel-stock-in-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['stock-ins']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('stock-ins.history')->call('openEdit', $entry->id)->assertStatus(403);
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Clerk', 'permissions' => ['stock-ins', 'stock-ins-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('stock-ins.history')
            ->set('cancelingId', $entry->id)
            ->set('cancelReason', 'test')
            ->call('cancelEntry');

        $this->assertTrue($entry->fresh()->isCancelled());
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['stock-ins', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        Livewire::test('stock-ins.history')->call('openCancel', $entry->id)->assertStatus(403);
    }

    public function test_cannot_add_payment_or_mark_as_paid_on_a_cancelled_entry(): void
    {
        [$shop, $owner, $product, $entry] = $this->shopWithStockIn();
        $entry->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        CancelStockIn::handle($entry, $owner->id, null);

        Livewire::test('stock-ins.history')->call('markAsPaid', $entry->id)->assertStatus(403);
    }

    public function test_stock_in_cancel_and_edit_tenant_isolation(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        $productB = Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'B', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $entryB = StockIn::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'product_name' => 'B', 'user_id' => $ownerB->id, 'quantity' => 5, 'payment_status' => 'paid', 'amount_paid' => 0, 'stock_date' => '2026-01-10']);

        $this->actingAs($ownerA);

        try {
            Livewire::test('stock-ins.history')
                ->set('cancelingId', $entryB->id)
                ->call('cancelEntry');
            $this->fail('Expected a ModelNotFoundException when cancelling another shop\'s stock-in entry.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertFalse($entryB->fresh()->isCancelled());
        $this->assertSame(10, $productB->fresh()->stock_quantity);
    }
}
