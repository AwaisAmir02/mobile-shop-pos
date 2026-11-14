<?php

namespace Tests\Feature;

use App\Actions\ReceiveGoods;
use App\Actions\SavePurchaseOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock In and Purchase Orders are two ways stock arrives. The sidebar names
 * and captions say which is which, a receipt made on an order shows the
 * order, and Add Stock points at orders still waiting for delivery.
 */
class StockInPurchaseOrdersNavTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Ali Traders', 'purchase_orders_enabled' => true]);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);
        $this->vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Acme']);
        $this->product = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 500, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($this->owner);
    }

    protected function order(int $quantity = 5)
    {
        return SavePurchaseOrder::handle(null, ['vendor_id' => $this->vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null],
            [['product_id' => $this->product->id, 'quantity' => $quantity, 'expected_cost' => 100.0]], $this->owner->id);
    }

    public function test_the_sidebar_names_both_and_says_what_each_is_for(): void
    {
        $html = $this->get(route('profile'))->assertOk()->getContent();

        $this->assertStringContainsString('Purchase Orders', $html);
        $this->assertStringNotContainsString('>Purchasing<', $html);
        $this->assertStringContainsString('Goods that have arrived', $html);
        $this->assertStringContainsString('Order first, receive later', $html);
    }

    public function test_a_receipt_made_on_an_order_shows_the_order_in_stock_in_history(): void
    {
        $order = $this->order();
        ReceiveGoods::handle($order->fresh(), [['po_line_id' => $order->lines->first()->id, 'quantity' => 5, 'cost_price' => 100.0, 'selling_price' => 150.0]], now()->toDateString(), null, null, $this->owner->id);

        $this->get(route('stock-ins.history'))->assertOk()
            ->assertSee('including goods received on purchase orders')
            ->assertSee('Received on')->assertSee($order->number())->assertSee(route('purchase-orders.show', $order), false);
    }

    public function test_add_stock_points_at_orders_waiting_for_delivery(): void
    {
        $this->get(route('stock-ins.index'))->assertOk()->assertSee('without a purchase order')->assertDontSee('data-open-po-notice', false);

        $this->order();
        $this->get(route('stock-ins.index'))->assertSee('data-open-po-notice', false)->assertSee('1 purchase order is waiting for delivery');

        // Someone who can't receive goods isn't sent there.
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Stock', 'permissions' => ['stock-ins']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]))
            ->get(route('stock-ins.index'))->assertOk()->assertDontSee('data-open-po-notice', false);

        // Nor is a shop without purchase orders on its plan.
        $this->shop->update(['purchase_orders_enabled' => false]);
        $this->owner->unsetRelation('shop');
        $this->actingAs($this->owner)->get(route('stock-ins.index'))->assertDontSee('data-open-po-notice', false);
    }

    public function test_the_purchase_orders_screens_say_how_they_differ(): void
    {
        $this->get(route('purchase-orders.index'))->assertOk()->assertSee('Order before the goods arrive, then receive against the order');
        $this->get(route('purchase-orders.create'))->assertOk()->assertSee('Purchase Orders')->assertDontSee('>Purchasing<', false);
    }
}
