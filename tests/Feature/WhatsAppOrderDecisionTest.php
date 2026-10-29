<?php

namespace Tests\Feature;

use App\Enums\SaleSource;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppOrderDecisionTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function pendingOrder(Shop $shop, array $lines, ?int $customerId = null, $connection = null): WhatsAppOrder
    {
        $contact = WhatsAppContact::create([
            'shop_id' => $shop->id,
            'shop_channel_connection_id' => $connection?->id,
            'wa_id' => '923001234567',
            'profile_name' => 'Ali',
            'customer_id' => $customerId,
            'last_inbound_at' => now(),
        ]);

        $order = WhatsAppOrder::create([
            'shop_id' => $shop->id,
            'whatsapp_contact_id' => $contact->id,
            'customer_id' => $customerId,
            'status' => WhatsAppOrderStatus::Pending,
            'submitted_at' => now(),
            'delivery_address' => 'Lahore',
        ]);

        foreach ($lines as [$product, $quantity, $price]) {
            WhatsAppOrderItem::create([
                'shop_id' => $shop->id,
                'whatsapp_order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'quoted_unit_price' => $price,
            ]);
        }

        $order->recalculateQuotedTotal();

        return $order;
    }

    public function test_accepting_creates_a_whatsapp_sale_through_fifo_and_sends_the_invoice(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 10);
        $batch = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Charger', 'user_id' => $owner->id, 'quantity' => 10, 'cost_price' => 1000, 'selling_price' => 1500, 'quantity_remaining' => 10, 'total_cost' => 10000, 'payment_status' => 'paid', 'amount_paid' => 10000, 'stock_date' => '2026-01-01']);
        $order = $this->pendingOrder($shop, [[$product, 3, 1400]], connection: $connection);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirect();

        $sale = Sale::sole();
        $this->assertSame(SaleSource::WhatsApp, $sale->source);
        $this->assertSame(4200.0, (float) $sale->total);
        $this->assertSame(4200.0, $sale->amountPaid());
        $this->assertSame(1400.0, (float) $sale->items()->sole()->unit_price);
        $this->assertSame($batch->id, $sale->items()->sole()->stock_in_id);
        $this->assertSame(7, $batch->fresh()->quantity_remaining);
        $this->assertSame(7, $product->fresh()->stock_quantity);

        $order->refresh();
        $this->assertSame(WhatsAppOrderStatus::Accepted, $order->status);
        $this->assertSame($sale->id, $order->sale_id);
        $this->assertSame($owner->id, $order->decided_by);
        $this->assertNotNull($order->customer_notified_at);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media'));
        $this->assertSame('document', collect($this->sentBodies())->last()['type']);
    }

    public function test_partial_payment_requires_a_customer_and_then_leaves_a_sales_balance_not_udhaar(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 10);
        $order = $this->pendingOrder($shop, [[$product, 2, 1500]], connection: $connection);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->set('amountPaid', '1000')
            ->call('accept')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->set('amountPaid', '1000')
            ->set('customerId', (string) $customer->id)
            ->call('accept')
            ->assertHasNoErrors();

        $this->assertSame(2000.0, $customer->fresh()->salesOutstandingBalance());
        $this->assertSame(0.0, $customer->fresh()->udhaarBalance());
        $this->assertSame($customer->id, WhatsAppContact::sole()->customer_id);
    }

    public function test_stock_shortfall_at_accept_time_is_reported_on_the_line_and_nothing_posts(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 1);
        $order = $this->pendingOrder($shop, [[$product, 3, 1500]], connection: $connection);
        $itemId = $order->items()->sole()->id;
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->call('accept')
            ->assertHasErrors(["lines.$itemId.quantity"]);

        $this->assertSame(0, Sale::count());
        $this->assertSame(WhatsAppOrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_order_can_only_be_decided_once(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 10);
        $order = $this->pendingOrder($shop, [[$product, 1, 1500]], connection: $connection);
        $this->actingAs($owner);

        $first = Livewire::test('whatsapp-orders.show', ['order' => $order]);
        $second = Livewire::test('whatsapp-orders.show', ['order' => $order]);

        $first->call('accept')->assertHasNoErrors();
        $second->call('accept')->assertHasErrors(['order']);

        $this->assertSame(1, Sale::count());
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_rejecting_notifies_the_customer_and_touches_no_stock(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 10);
        $order = $this->pendingOrder($shop, [[$product, 2, 1500]], connection: $connection);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->call('reject')
            ->assertHasErrors(['rejectReason'])
            ->set('rejectReason', 'Out of stock in black')
            ->call('reject')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(WhatsAppOrderStatus::Rejected, $order->status);
        $this->assertSame('Out of stock in black', $order->reject_reason);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(0, Sale::count());
        $this->assertStringContainsString('Out of stock in black', collect($this->sentBodies())->last()['text']['body']);
    }

    public function test_notification_outside_the_24h_window_is_flagged_not_sent(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 10);
        $order = $this->pendingOrder($shop, [[$product, 1, 1500]], connection: $connection);
        WhatsAppContact::whereKey($order->whatsapp_contact_id)->update(['last_inbound_at' => now()->subDays(2)]);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])->call('accept')->assertHasNoErrors();

        $this->assertSame(1, Sale::count());
        $this->assertNull($order->fresh()->customer_notified_at);
        $this->assertNotNull($order->fresh()->notify_error);
        $this->assertCount(0, $this->sentBodies());
    }

    public function test_staff_without_decide_permission_can_view_but_not_accept(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop);
        $order = $this->pendingOrder($shop, [[$product, 1, 1500]], connection: $connection);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['whatsapp-orders']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->assertOk()
            ->call('accept')
            ->assertForbidden();

        $this->assertSame(0, Sale::count());
    }

    public function test_another_shops_order_is_not_reachable(): void
    {
        [$shopA, $ownerA] = $this->whatsappShop('Shop A', '111');
        [$shopB, , $connB] = $this->whatsappShop('Shop B', '222');
        $order = $this->pendingOrder($shopB, [[$this->listedProduct($shopB), 1, 1500]], connection: $connB);

        $this->actingAs($ownerA)->get(route('whatsapp-orders.show', $order->id))->assertNotFound();
    }

    public function test_inbox_is_hidden_when_whatsapp_is_not_enabled_for_the_shop(): void
    {
        $shop = Shop::create(['name' => 'Plain shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        $this->actingAs($owner)->get(route('whatsapp-orders.index'))->assertForbidden();
    }
}
