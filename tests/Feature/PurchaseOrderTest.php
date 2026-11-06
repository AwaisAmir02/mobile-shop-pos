<?php

namespace Tests\Feature;

use App\Actions\CancelGrn;
use App\Actions\CancelPurchaseOrder;
use App\Actions\CancelStockIn;
use App\Actions\CancelStockWriteOff;
use App\Actions\CreateStockWriteOff;
use App\Actions\ReceiveGoods;
use App\Actions\SavePurchaseOrder;
use App\Actions\UpdateStockIn;
use App\Enums\FeatureKey;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuantityUnit;
use App\Enums\WriteOffReason;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\GrnReport;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
use App\Services\PurchaseOrderPdfService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Vendor $vendor;

    protected function poShop(array $attributes = []): Shop
    {
        $shop = $this->shop($attributes + ['purchase_orders_enabled' => true]);
        $this->vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme']);

        return $shop;
    }

    /** @param array<int, array{0: Product, 1: mixed, 2?: ?float}> $lines */
    protected function order(array $lines): PurchaseOrder
    {
        return SavePurchaseOrder::handle(null, [
            'vendor_id' => $this->vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null,
        ], array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1], 'expected_cost' => $line[2] ?? 100.0], $lines), auth()->id());
    }

    protected function receive(PurchaseOrder $order, array $lines, bool $confirm = false, ?int $branchId = null)
    {
        return ReceiveGoods::handle($order->fresh(), array_map(fn (array $line) => $line + ['cost_price' => 100.0, 'selling_price' => 150.0], $lines), now()->toDateString(), $branchId, null, auth()->id(), $confirm);
    }

    protected function statusOf(PurchaseOrder $order): PurchaseOrderStatus
    {
        return PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->find($order->id)->status;
    }

    // ── An order moves nothing ─────────────────────────────────────────

    public function test_an_order_moves_no_stock_and_no_money(): void
    {
        $this->poShop();
        $product = $this->piece(Shop::first());

        $order = $this->order([[$product, 10]]);

        $this->assertSame(PurchaseOrderStatus::Open, $order->status);
        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0, StockIn::count());
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(1000.0, $order->orderedAmount());
    }

    // ── Receiving ──────────────────────────────────────────────────────

    public function test_one_grn_line_makes_exactly_one_batch_and_dues_arise_once_on_it(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);
        $line = $order->lines->first();

        $grn = $this->receive($order, [['po_line_id' => $line->id, 'quantity' => 4]]);

        $batch = StockIn::sole();
        $this->assertSame($grn->lines->first()->id, $batch->grn_line_id);
        $this->assertSame($this->vendor->id, $batch->vendor_id);
        $this->assertSame(4, $batch->quantity);
        $this->assertSame(4, $batch->quantity_remaining);
        $this->assertSame(400.0, (float) $batch->total_cost);
        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertSame(400.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(0, StockInPayment::count());
    }

    public function test_received_stock_sells_through_the_normal_batch_path_at_its_own_cost(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);

        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 4]]);

        $item = $this->sell($product->fresh(), 1)->items()->first();

        $this->assertSame(StockIn::sole()->id, $item->stock_in_id);
        $this->assertSame(100.0, (float) $item->cost_price);
        $this->assertSame(150.0, (float) $item->unit_price);
    }

    public function test_part_deliveries_move_the_status_and_received_is_always_summed(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);
        $line = $order->lines->first();

        $this->receive($order, [['po_line_id' => $line->id, 'quantity' => 4]]);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $this->statusOf($order));
        $this->assertSame(6, $line->fresh()->outstandingQuantity());
        $this->assertSame(600.0, $order->fresh()->outstandingAmount());

        $this->receive($order, [['po_line_id' => $line->id, 'quantity' => 6]]);
        $this->assertSame(PurchaseOrderStatus::FullyReceived, $this->statusOf($order));
        $this->assertSame(10, $line->fresh()->receivedQuantity());
        $this->assertSame(2, StockIn::count());
    }

    public function test_every_line_must_arrive_for_fully_received(): void
    {
        $shop = $this->poShop();
        $a = $this->piece($shop, ['name' => 'A']);
        $b = $this->piece($shop, ['name' => 'B']);
        $order = $this->order([[$a, 2], [$b, 2]]);

        $this->receive($order, [['po_line_id' => $order->lines[0]->id, 'quantity' => 2]]);

        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $this->statusOf($order));
    }

    public function test_over_receipt_needs_explicit_confirmation(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 3]]);
        $line = ['po_line_id' => $order->lines->first()->id, 'quantity' => 5];

        $this->assertRefused(fn () => $this->receive($order, [$line]), '5 against 3 ordered');
        $this->assertSame(0, StockIn::count());

        $this->receive($order, [$line], confirm: true);

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(PurchaseOrderStatus::FullyReceived, $this->statusOf($order));
    }

    public function test_over_receipt_counts_all_rows_of_a_delivery_together(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 3]]);
        $id = $order->lines->first()->id;

        $this->assertRefused(fn () => $this->receive($order, [['po_line_id' => $id, 'quantity' => 2], ['po_line_id' => $id, 'quantity' => 2]]), 'ordered');
    }

    public function test_a_delivery_can_carry_two_expiry_dates_as_two_batches(): void
    {
        $product = $this->product($this->poShop(['expiry_tracking_enabled' => true]));
        $order = $this->order([[$product, 5]]);
        $id = $order->lines->first()->id;

        $this->receive($order, [
            ['po_line_id' => $id, 'quantity' => 2.5, 'expiry_date' => '2030-01-01'],
            ['po_line_id' => $id, 'quantity' => 2.5, 'expiry_date' => '2030-06-01'],
        ]);

        $this->assertSame(['2030-01-01', '2030-06-01'], StockIn::orderBy('id')->get()->map(fn ($b) => $b->expiry_date->toDateString())->all());
        $this->assertSame(PurchaseOrderStatus::FullyReceived, $this->statusOf($order));
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_measured_quantities_follow_the_unit_rules_everywhere(): void
    {
        $shop = $this->poShop();
        $rice = $this->product($shop);
        $cable = $this->piece($shop);

        $this->assertRefused(fn () => $this->order([[$cable, 1.5]]), 'whole number');

        $order = $this->order([[$rice, 2.5], [$cable, 3]]);
        $this->assertSame(QuantityUnit::Kg, $order->lines[0]->unit);
        $this->assertSame('3 pieces · 2.5 kg', $order->outstandingSummary());

        $this->assertRefused(fn () => $this->receive($order, [['po_line_id' => $order->lines[1]->id, 'quantity' => 1.5]]), 'whole number');

        $this->receive($order, [['po_line_id' => $order->lines[0]->id, 'quantity' => 1.25]]);
        $this->assertSame(1.25, $order->lines[0]->fresh()->outstandingQuantity());
        $this->assertSame(1.25, $rice->fresh()->stock_quantity);
    }

    public function test_each_variant_is_its_own_line(): void
    {
        $shop = $this->poShop(['variants_enabled' => true]);
        $service = app(ProductGroupService::class);
        [$red, $blue] = $service->addVariants($service->createGroup($shop->id, 'Shirt', 'grocery', ''), ['Red', 'Blue'], 500, null);

        $order = $this->order([[$red, 2], [$blue, 3]]);
        $this->receive($order, [['po_line_id' => $order->lines[0]->id, 'quantity' => 2]]);

        $this->assertSame(2, $red->fresh()->stock_quantity);
        $this->assertSame(0, $blue->fresh()->stock_quantity);
        $this->assertSame('Shirt - Red', StockIn::sole()->product_name);
    }

    public function test_one_product_cannot_be_on_two_lines(): void
    {
        $product = $this->piece($this->poShop());

        $this->assertRefused(fn () => $this->order([[$product, 1], [$product, 2]]), 'one line');
    }

    public function test_nothing_can_be_received_against_a_cancelled_or_closed_order(): void
    {
        $product = $this->piece($this->poShop());
        $cancelled = $this->order([[$product, 3]]);
        $cancelledLine = $cancelled->lines->first()->id;
        CancelPurchaseOrder::handle($cancelled, auth()->id());

        $this->assertRefused(fn () => ReceiveGoods::handle(PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->find($cancelled->id), [['po_line_id' => $cancelledLine, 'quantity' => 1, 'cost_price' => 1.0, 'selling_price' => 1.0]], now()->toDateString(), null, null, auth()->id()), 'nothing more can be received');

        $closed = $this->order([[$product, 3]]);
        $closedLine = $closed->lines->first()->id;
        $this->receive($closed, [['po_line_id' => $closedLine, 'quantity' => 1]]);
        CancelPurchaseOrder::closeShort($closed->fresh(), auth()->id());

        $this->assertRefused(fn () => $this->receive($closed, [['po_line_id' => $closedLine, 'quantity' => 1]]), 'nothing more can be received');
    }

    // ── Unit lock ──────────────────────────────────────────────────────

    public function test_an_open_order_locks_the_products_unit_until_closed_or_cancelled(): void
    {
        $product = $this->product($this->poShop());
        $this->assertFalse($product->unitIsLocked());

        $order = $this->order([[$product, 3]]);
        $this->assertTrue($product->fresh()->unitIsLocked());
        $this->assertRefused(fn () => $product->fresh()->update(['unit' => 'litre']), "can't be changed");

        CancelPurchaseOrder::handle($order, auth()->id());
        $this->assertFalse($product->fresh()->unitIsLocked());
    }

    // ── Cancelling and closing ─────────────────────────────────────────

    public function test_an_order_with_goods_received_is_closed_short_not_cancelled(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);
        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 4]]);

        $this->assertRefused(fn () => CancelPurchaseOrder::handle($order->fresh(), auth()->id()), 'Close it short');

        CancelPurchaseOrder::closeShort($order->fresh(), auth()->id());

        $this->assertSame(PurchaseOrderStatus::Closed, $this->statusOf($order));
        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertSame(400.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_an_order_with_nothing_received_is_cancelled_not_closed(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);

        $this->assertRefused(fn () => CancelPurchaseOrder::closeShort($order->fresh(), auth()->id()), 'Cancel it instead');

        CancelPurchaseOrder::handle($order->fresh(), auth()->id(), 'Vendor out of stock');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $this->statusOf($order));
    }

    public function test_cancelling_a_grn_takes_the_stock_and_dues_back_and_reopens_the_order(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 10]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 10]]);
        $this->assertSame(PurchaseOrderStatus::FullyReceived, $this->statusOf($order));

        CancelGrn::handle($grn, auth()->id(), 'Wrong delivery');

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(0, StockIn::count());
        $this->assertSame(10, $order->lines->first()->fresh()->outstandingQuantity());
        $this->assertSame(PurchaseOrderStatus::Open, $this->statusOf($order));
        $this->expectException(\RuntimeException::class);
        CancelGrn::handle($grn, auth()->id());
    }

    public function test_a_grn_with_sold_stock_cannot_be_cancelled_and_no_sale_is_touched(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 5]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);
        $sale = $this->sell($product->fresh(), 1);

        $this->assertRefused(fn () => CancelGrn::handle($grn, auth()->id()), 'already been sold');

        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertSame(4, StockIn::sole()->quantity_remaining);
        $this->assertFalse($grn->fresh()->isCancelled());
        $this->assertFalse($sale->fresh()->isCancelled());
    }

    public function test_a_grn_with_written_off_stock_cannot_be_cancelled_until_that_is_reversed(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 5]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);
        $writeOff = CreateStockWriteOff::handle($product->fresh(), 1, WriteOffReason::Damaged, null, auth()->id(), now()->toDateString());

        // A write-off keeps the vendor balance: it isn't a return.
        $this->assertSame(500.0, $this->vendor->fresh()->totalOwed());
        $this->assertRefused(fn () => CancelGrn::handle($grn, auth()->id()), 'sold or written off');

        CancelStockWriteOff::handle($writeOff, auth()->id());
        CancelGrn::handle($grn, auth()->id());

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_a_grn_with_a_payment_cannot_be_cancelled(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 5]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);

        Livewire::test('stock-ins.history')->call('markAsPaid', StockIn::sole()->id);

        $this->assertRefused(fn () => CancelGrn::handle($grn, auth()->id()), 'payment is recorded');
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_a_receipts_batch_cannot_be_changed_behind_the_order(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 5]]);
        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);
        $batch = StockIn::sole();
        $fields = ['vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 90.0, 'selling_price' => 150.0];

        $this->assertRefused(fn () => CancelStockIn::handle($batch, auth()->id()), 'Cancel the receipt');
        $this->assertRefused(fn () => UpdateStockIn::handle($batch, ['quantity' => 3] + $fields, auth()->id()), 'Cancel the receipt');

        // Prices can still be corrected, and the balance follows the batch.
        UpdateStockIn::handle($batch, ['quantity' => 5] + $fields, auth()->id());
        $this->assertSame(450.0, $this->vendor->fresh()->totalOwed());
    }

    // ── The fast path ──────────────────────────────────────────────────

    public function test_plain_stock_in_still_works_with_no_grn_link(): void
    {
        $product = $this->piece($this->poShop());

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->set('quantity', '2')->set('costPrice', '10')->set('sellingPrice', '15')
            ->call('addLine')->set('paymentStatus', 'unpaid')->call('save')->assertHasNoErrors();

        $batch = StockIn::sole();
        $this->assertNull($batch->grn_line_id);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        CancelStockIn::handle($batch, auth()->id());
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    // ── Branches ───────────────────────────────────────────────────────

    public function test_the_receiving_branch_goes_on_the_grn_and_the_batch(): void
    {
        $shop = $this->poShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        $product = $this->piece($shop);
        $order = $this->order([[$product, 5]]);

        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]], branchId: $second->id);

        $this->assertSame($second->id, $grn->branch_id);
        $this->assertSame($second->id, StockIn::sole()->branch_id);
        // Still one shared pool.
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_branch_restricted_staff_see_their_own_orders_and_receive_only_at_their_branch(): void
    {
        $shop = $this->poShop();
        $main = BranchContext::current()->mainBranchIdFor($shop->id);
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $product = $this->piece($shop);
        $order = $this->order([[$product, 5]]);
        $this->assertSame($main, $order->branch_id);

        $elsewhere = SavePurchaseOrder::handle(null, ['vendor_id' => $this->vendor->id, 'branch_id' => $second->id, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $product->id, 'quantity' => 1, 'expected_cost' => 1.0]], auth()->id());

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Staff', 'permissions' => ['purchase-orders', 'receive-goods']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id, 'branch_id' => $second->id]));

        Livewire::test('purchase-orders.index')->assertSee($elsewhere->number())->assertDontSee($order->number());
        $this->get(route('purchase-orders.show', $order->id))->assertNotFound();

        // A crafted branch is ignored.
        $grn = $this->receive($elsewhere, [['po_line_id' => $elsewhere->lines->first()->id, 'quantity' => 1]], branchId: $main);
        $this->assertSame($second->id, $grn->branch_id);
    }

    public function test_a_po_counts_deliveries_from_every_branch_whoever_is_looking(): void
    {
        $shop = $this->poShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        $product = $this->piece($shop);
        $order = $this->order([[$product, 5]]);
        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 2]], branchId: $second->id);

        $this->assertSame(2, BranchContext::current()->forBranch(BranchContext::current()->mainBranchIdFor($shop->id), fn () => $order->lines->first()->fresh()->receivedQuantity()));
    }

    // ── Plan ───────────────────────────────────────────────────────────

    public function test_a_received_batchs_vendor_is_locked_like_its_quantity(): void
    {
        $shop = $this->poShop();
        $product = $this->piece($shop);
        $order = $this->order([[$product, 5]]);
        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);
        $batch = StockIn::sole();
        $other = Vendor::create(['shop_id' => $shop->id, 'name' => 'Other Vendor']);
        $fields = ['quantity' => 5, 'vendor_id' => $batch->vendor_id, 'stock_date' => now()->toDateString(), 'note' => 'Fixed', 'cost_price' => 100.0, 'selling_price' => 150.0];

        foreach ([$other->id, null] as $vendorId) {
            try {
                UpdateStockIn::handle($batch, ['vendor_id' => $vendorId] + $fields, auth()->id());
                $this->fail('A received batch must keep its vendor.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('came from a goods receipt', $e->errors()['editVendorId'][0]);
            }
        }

        $this->assertSame($this->vendor->id, $batch->fresh()->vendor_id);
        $this->assertSame(500.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(0.0, $other->fresh()->totalOwed());

        // Other fields still save, with the vendor as it was.
        UpdateStockIn::handle($batch, $fields, auth()->id());
        $this->assertSame('Fixed', $batch->fresh()->note);

        // Through the screen: the field is read-only, and a forged value is refused.
        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->assertSee('its quantity and vendor can\'t be changed here', false)
            ->assertSeeHtml('id="editVendorId"')
            ->assertDontSeeHtml('wire:model="editVendorId"')
            ->set('editVendorId', (string) $other->id)
            ->call('saveEdit')
            ->assertHasErrors('editVendorId');

        $this->assertSame($this->vendor->id, $batch->fresh()->vendor_id);
    }

    public function test_a_batch_from_plain_stock_in_can_still_change_vendor(): void
    {
        $shop = $this->poShop();
        $product = $this->piece($shop);
        $batch = \App\Actions\CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 5, 'cost_price' => 100.0, 'selling_price' => 150.0]], $this->vendor->id, 'unpaid', null, now()->toDateString(), null, auth()->id())->first();
        $other = Vendor::create(['shop_id' => $shop->id, 'name' => 'Other Vendor']);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->assertSeeHtml('wire:model="editVendorId"')
            ->set('editVendorId', (string) $other->id)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame($other->id, $batch->fresh()->vendor_id);
        $this->assertSame(500.0, $other->fresh()->totalOwed());
    }

    public function test_it_is_plan_controlled_included_in_commerce_only_and_can_be_an_add_on(): void
    {
        $this->assertTrue(FeatureKey::PurchaseOrders->planControlled());
        $this->assertTrue(Plan::where('name', 'Commerce')->sole()->feature_flags['purchase_orders']);
        $this->assertFalse(Plan::where('name', 'Growth')->sole()->feature_flags['purchase_orders']);

        $shop = Shop::create(['name' => 'Starter', 'plan_id' => Plan::where('name', 'Starter')->sole()->id]);
        $this->assertFalse($shop->fresh()->purchase_orders_enabled);

        $addon = Addon::create(['name' => 'Purchase orders', 'kind' => 'feature_unlock', 'feature_keys' => [FeatureKey::PurchaseOrders->value], 'price' => 1000, 'billing' => 'monthly']);
        ShopAddon::create(['shop_id' => $shop->id, 'addon_id' => $addon->id, 'name' => $addon->name, 'kind' => 'feature_unlock', 'feature_keys' => [FeatureKey::PurchaseOrders->value], 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'price' => 1000, 'billing' => 'monthly']);

        app(Entitlements::class)->syncFeatureFlags($shop->fresh());

        $this->assertTrue($shop->fresh()->purchase_orders_enabled);
    }

    public function test_downgrading_leaves_dues_alone_and_makes_orders_read_only(): void
    {
        $shop = $this->poShop();
        $product = $this->piece($shop);
        $order = $this->order([[$product, 10]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 4]]);

        $shop->update(['purchase_orders_enabled' => false]);
        auth()->user()->unsetRelation('shop');

        $this->assertSame(400.0, $this->vendor->fresh()->totalOwed());
        $this->assertRefused(fn () => $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 1]]), 'read-only');
        $this->assertRefused(fn () => CancelPurchaseOrder::closeShort($order->fresh(), auth()->id()), 'read-only');

        Livewire::test('purchase-orders.index')->assertSee($order->number())->assertSee('read-only')->assertDontSee('New Purchase Order');
        Livewire::test('purchase-orders.show', ['order' => $order->id])->assertDontSee('Receive Goods')->assertSee('read-only');
        $this->get(route('purchase-orders.create'))->assertForbidden();
        $this->get(route('purchase-orders.receive', $order->id))->assertForbidden();

        // Falling back to plain Stock In works, and a mistaken delivery can still be undone.
        Livewire::test('stock-ins.create')->set('productId', (string) $product->id)->set('quantity', '1')->set('sellingPrice', '10')->call('addLine')->call('save')->assertHasNoErrors();
        CancelGrn::handle($grn, auth()->id());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_full_flow_through_the_screens(): void
    {
        $shop = $this->poShop();
        $product = $this->product($shop, ['cost_price' => 250, 'price' => 300]);

        Livewire::test('purchase-orders.form')
            ->set('vendorId', (string) $this->vendor->id)
            ->set('lines.1.product_id', (string) $product->id)
            ->assertSet('lines.1.expected_cost', '250.00')
            ->set('lines.1.quantity', '2.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = PurchaseOrder::sole();

        Livewire::test('purchase-orders.index')->assertSee($order->number())->assertSee('2.5 kg')->assertSee('Rs 625.00')->assertSee('Open');

        Livewire::test('purchase-orders.receive', ['order' => $order->id])
            ->assertSet('rows.1.quantity', '2.5')
            ->assertSet('rows.1.cost_price', '250.00')
            ->assertSet('rows.1.selling_price', '300.00')
            ->set('rows.1.quantity', '1.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1.5, $product->fresh()->stock_quantity);
        $this->assertSame(375.0, $this->vendor->fresh()->totalOwed());

        Livewire::test('purchase-orders.show', ['order' => $order->id])->assertSee('Partly received')->assertSee('1 kg')->assertDontSee('>Edit<', false);
        Livewire::test('purchase-orders.receipts')->assertSee('GRN-')->assertSee('Basmati Rice × 1.5 kg');
    }

    public function test_the_receive_screen_asks_before_taking_more_than_ordered(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 3]]);

        Livewire::test('purchase-orders.receive', ['order' => $order->id])
            ->set('rows.1.quantity', '5')
            ->call('save')
            ->assertHasErrors('overReceipt')
            ->assertSee('Yes, this is what arrived')
            ->set('confirmOverReceipt', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_the_receive_screen_prefills_shelf_life_and_splits_a_batch(): void
    {
        $product = $this->product($this->poShop(['expiry_tracking_enabled' => true]), ['shelf_life_days' => 30]);
        $order = $this->order([[$product, 5]]);
        $date = now()->addDays(30)->toDateString();

        Livewire::test('purchase-orders.receive', ['order' => $order->id])
            ->assertSee('Expiry Date')
            ->assertSet('rows.1.expiry_date', $date)
            ->call('addBatch', 1)
            ->assertSet('rows.2.quantity', '')
            ->set('rows.1.quantity', '2')
            ->set('rows.2.quantity', '3')
            ->set('rows.2.expiry_date', '2031-01-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$date, '2031-01-01'], StockIn::orderBy('id')->get()->map(fn ($b) => $b->expiry_date->toDateString())->all());
    }

    public function test_a_blank_row_is_skipped_and_an_all_blank_delivery_is_refused(): void
    {
        $shop = $this->poShop();
        $a = $this->piece($shop, ['name' => 'A']);
        $b = $this->piece($shop, ['name' => 'B']);
        $order = $this->order([[$a, 2], [$b, 2]]);

        Livewire::test('purchase-orders.receive', ['order' => $order->id])
            ->set('rows.1.quantity', '')->set('rows.2.quantity', '')
            ->call('save')->assertHasErrors('lines')
            ->set('rows.2.quantity', '2')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0, $a->fresh()->stock_quantity);
        $this->assertSame(2, $b->fresh()->stock_quantity);
    }

    public function test_editing_works_until_goods_arrive_and_keeps_a_revision(): void
    {
        $shop = $this->poShop();
        $a = $this->piece($shop, ['name' => 'A']);
        $order = $this->order([[$a, 2]]);

        Livewire::test('purchase-orders.form', ['order' => $order->id])
            ->assertSet('lines.1.quantity', '2')
            ->set('lines.1.quantity', '7')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7, $order->fresh('lines')->lines->first()->quantity);
        $this->assertSame(1, $order->fresh()->revisions()->count());

        $this->receive($order, [['po_line_id' => $order->fresh('lines')->lines->first()->id, 'quantity' => 1]]);

        $this->get(route('purchase-orders.edit', $order->id))->assertForbidden();
    }

    public function test_the_receipts_screen_cancels_and_shows_why_it_cant(): void
    {
        $product = $this->piece($this->poShop());
        $order = $this->order([[$product, 5]]);
        $grn = $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 5]]);

        $this->sell($product->fresh(), 1);

        Livewire::test('purchase-orders.receipts')->call('openCancel', $grn->id)->call('cancelGrn')->assertHasErrors('quantity');
        $this->assertFalse($grn->fresh()->isCancelled());
    }

    public function test_access_is_separate_from_stock_in_for_each_screen(): void
    {
        $shop = $this->poShop();
        $order = $this->order([[$this->piece($shop), 5]]);

        $stockOnly = Role::create(['shop_id' => $shop->id, 'name' => 'Stock', 'permissions' => ['stock-ins']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $stockOnly->id]));
        $this->get(route('purchase-orders.index'))->assertForbidden();
        $this->get(route('purchase-orders.receive', $order->id))->assertForbidden();

        $receiver = Role::create(['shop_id' => $shop->id, 'name' => 'Receiver', 'permissions' => ['receive-goods']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $receiver->id]));
        $this->get(route('purchase-orders.index'))->assertOk();
        $this->get(route('purchase-orders.receive', $order->id))->assertOk();
        $this->get(route('purchase-orders.create'))->assertForbidden();
        Livewire::test('purchase-orders.show', ['order' => $order->id])->assertSee('Receive Goods')->assertDontSee('Cancel Order');
    }

    public function test_another_shops_orders_are_out_of_reach(): void
    {
        $this->poShop();
        $mine = $this->order([[$this->piece(Shop::first()), 1]]);

        $this->actingAs(User::factory()->create(['shop_id' => Shop::create(['name' => 'Other', 'purchase_orders_enabled' => true])->id, 'is_owner' => true]));

        $this->get(route('purchase-orders.show', $mine->id))->assertNotFound();
        Livewire::test('purchase-orders.index')->assertDontSee($mine->number());
    }

    public function test_a_vendor_with_orders_cannot_be_deleted(): void
    {
        $this->order([[$this->piece($this->poShop()), 1]]);

        Livewire::test('vendors.index')->call('delete', $this->vendor->id);

        $this->assertNotNull($this->vendor->fresh());
    }

    public function test_the_pdf_and_receipts_export_render(): void
    {
        $product = $this->product($this->poShop());
        $order = $this->order([[$product, 2.5, 33.33]]);
        $this->receive($order, [['po_line_id' => $order->lines->first()->id, 'quantity' => 1.5]]);

        $html = view('pdf.purchase-order', ['order' => $order->fresh(['lines', 'vendor', 'shop'])])->render();
        $this->assertStringContainsString($order->number(), $html);
        $this->assertStringContainsString('2.5 kg', $html);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringStartsWith('%PDF', app(PurchaseOrderPdfService::class)->render($order->fresh()));

        $report = new GrnReport;
        $rows = $report->tableRows(forExcel: true);
        $this->assertSame(1.5, $rows[0][5]);
        $this->assertCount(count($report->tableHeaders()), $rows[0]);
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());
    }
}
