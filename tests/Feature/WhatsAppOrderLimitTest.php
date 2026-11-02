<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\Billing\Entitlements;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppOrderLimitTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Karachi'));
        $this->fakeGraph();
    }

    /** A WhatsApp shop on a plan allowing $limit orders a month, with its bot. */
    protected function shopAllowing(?int $limit): array
    {
        [$shop, $owner, $connection] = $this->whatsappShop();
        $plan = Plan::create(['name' => 'Orders plan', 'price' => 2000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => ['whatsapp_orders_per_month' => $limit], 'feature_flags' => ['whatsapp_ordering' => true, 'whatsapp_messaging' => true]]);
        $shop->update(['plan_id' => $plan->id]);
        $this->listedProduct($shop, 'Charger', 1500, 5);

        return [$shop->fresh(), $owner, new WhatsAppChatbot($connection->fresh()), $plan];
    }

    /** Puts one charger in the cart, enters delivery details and taps Confirm. */
    protected function placeOrder(WhatsAppChatbot $bot, Shop $shop): void
    {
        $product = $shop->products()->withoutGlobalScopes()->where('name', 'Charger')->firstOrFail();
        $tap = fn (string $id) => $bot->handleInbound($this->replyMessage($this->customer, $id), 'Ali WA');
        $type = fn (string $text) => $bot->handleInbound($this->textMessage($this->customer, $text), 'Ali WA');

        $tap("qty:{$product->id}:1");
        $tap('checkout');
        if (WhatsAppContact::withoutGlobalScopes()->where('wa_id', $this->customer)->first()?->hasSavedDelivery()) {
            $tap('details:use');
        } else {
            $tap('ship:delivery');
            foreach (['Ali Khan', 'House 5, Street 2', 'Model Town', '0300 1234567'] as $answer) {
                $type($answer);
            }
            $tap('save:yes');
        }
        $tap('confirm');
    }

    /** An order the customer placed at $at (default: now). */
    protected function placedOrder(Shop $shop, WhatsAppOrderStatus $status = WhatsAppOrderStatus::Pending, ?Carbon $at = null): WhatsAppOrder
    {
        $contact = WhatsAppContact::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'wa_id' => '923339998888']);

        return WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => $status, 'submitted_at' => $at ?? now(), 'quoted_total' => 1500]);
    }

    protected function lastText(): string
    {
        $last = collect($this->sentBodies())->last();

        return $last['type'] === 'text' ? $last['text']['body'] : $last['interactive']['body']['text'];
    }

    public function test_usage_counts_orders_placed_this_month_whatever_happened_to_them(): void
    {
        [$shop] = $this->shopAllowing(10);

        $this->placedOrder($shop);
        $this->placedOrder($shop, WhatsAppOrderStatus::Accepted);
        $this->placedOrder($shop, WhatsAppOrderStatus::Rejected);
        $this->placedOrder($shop, WhatsAppOrderStatus::Pending, now()->subMonth());
        WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => WhatsAppContact::withoutGlobalScopes()->firstOrFail()->id, 'status' => WhatsAppOrderStatus::Collecting]);

        $this->assertSame(3, app(Entitlements::class)->usage($shop, LimitKey::WhatsAppOrdersPerMonth), 'Placed this month only; an open cart is not an order yet.');
    }

    public function test_at_the_limit_confirm_keeps_the_cart_and_hands_the_customer_to_the_shop(): void
    {
        [$shop, , $bot] = $this->shopAllowing(1);
        $this->placedOrder($shop);

        $this->placeOrder($bot, $shop);

        $this->assertSame("Sorry, {$shop->invoiceDisplayName()} can't take more WhatsApp orders right now. Your cart is saved, and the shop will reply to you here personally.", $this->lastText());
        $cart = WhatsAppOrder::withoutGlobalScopes()->where('status', WhatsAppOrderStatus::Collecting->value)->sole();
        $this->assertNull($cart->submitted_at);
        $this->assertSame(1, $cart->items()->withoutGlobalScopes()->count(), 'The cart is kept.');
        $this->assertSame(1, app(Entitlements::class)->usage($shop, LimitKey::WhatsAppOrdersPerMonth));

        // Handed off: the bot stays quiet while the shop replies personally.
        $sent = count($this->sentBodies());
        $bot->handleInbound($this->textMessage($this->customer, 'hello?'), 'Ali WA');
        $this->assertCount($sent, $this->sentBodies());

        // Next month the same cart goes through.
        $this->travelTo(Carbon::parse('2026-12-02 12:00:00', 'Asia/Karachi'));
        $bot->handleInbound($this->replyMessage($this->customer, 'confirm'), 'Ali WA');
        $this->assertSame(WhatsAppOrderStatus::Pending, $cart->fresh()->status);
    }

    public function test_under_the_limit_unlimited_or_without_a_plan_orders_go_through(): void
    {
        [$shop, , $bot] = $this->shopAllowing(2);
        $this->placedOrder($shop);
        $this->placeOrder($bot, $shop);
        $this->assertSame(2, WhatsAppOrder::withoutGlobalScopes()->where('status', WhatsAppOrderStatus::Pending->value)->count());
        $this->assertStringContainsString('has been received', $this->lastText());

        $shop->plan->update(['limits' => ['whatsapp_orders_per_month' => null]]);
        $this->placeOrder($bot, $shop->fresh());
        $this->assertSame(3, WhatsAppOrder::withoutGlobalScopes()->where('status', WhatsAppOrderStatus::Pending->value)->count());

        $shop->update(['plan_id' => null]);
        $this->placeOrder($bot, $shop->fresh());
        $this->assertSame(4, WhatsAppOrder::withoutGlobalScopes()->where('status', WhatsAppOrderStatus::Pending->value)->count());
    }

    public function test_the_orders_screen_says_when_the_limit_is_near_or_reached(): void
    {
        [$shop, $owner] = $this->shopAllowing(5);
        foreach (range(1, 4) as $i) {
            $this->placedOrder($shop);
        }

        $this->actingAs($owner)->get(route('whatsapp-orders.index'))
            ->assertSee('data-limit-notice="whatsapp_orders_per_month"', false)
            ->assertSee('You’ve used 4 of 5 orders this month.');

        $this->placedOrder($shop);
        app(Entitlements::class)->forgetMeters($shop);

        $this->actingAs($owner)->get(route('whatsapp-orders.index'))
            ->assertSee('Plan limit reached')
            ->assertSee('You’ve used all 5 orders this month on your plan.')
            // The banner leaves out the limit this page already explains.
            ->assertDontSee('WhatsApp orders this month (5/5)');

        // A real request starts with an empty notice registry; one test reuses the app.
        app()->forgetScopedInstances();
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('WhatsApp orders this month (5/5)');
    }

    public function test_it_flows_through_the_plan_editor_admin_page_plan_and_usage_and_addon_packs(): void
    {
        $this->assertSame(LimitKey::WhatsAppOrdersPerMonth, FeatureKey::WhatsAppOrdering->limit());
        [$shop, $owner, , $plan] = $this->shopAllowing(null);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        // Plan editor: the ordering row's "Has a usage limit?" sets it.
        Livewire::test('admin.plans')->call('openEdit', $plan->id)
            ->assertSet('limited.whatsapp_orders_per_month', false)
            ->set('limited.whatsapp_orders_per_month', true)->set('limits.whatsapp_orders_per_month', '40')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(40, $plan->fresh()->limits['whatsapp_orders_per_month']);

        // Admin shop page: a per-shop override.
        Livewire::test('admin.shops.show', ['shop' => $shop->fresh()])
            ->assertSee('WhatsApp orders this month')->assertSee('Plan: 40')
            ->set('limitOverrides.whatsapp_orders_per_month', '60')->call('saveSubscription')->assertHasNoErrors();
        $this->assertSame(60, app(Entitlements::class)->limit($shop->fresh(), LimitKey::WhatsAppOrdersPerMonth));

        // A pack adds room on top.
        $pack = Addon::create(['name' => 'More orders', 'kind' => 'limit_pack', 'limit_key' => 'whatsapp_orders_per_month', 'quantity' => 25, 'price' => 500, 'billing' => 'one_off']);
        $this->assertSame('+25 orders a month', $pack->grants());
        ShopAddon::create(['shop_id' => $shop->id, 'addon_id' => $pack->id, 'status' => 'active', 'name' => $pack->name, 'kind' => 'limit_pack', 'limit_key' => 'whatsapp_orders_per_month', 'quantity' => 25, 'price' => 500, 'billing' => 'one_off', 'starts_at' => now()->subDay()]);
        $this->assertSame(85, app(Entitlements::class)->limit($shop->fresh(), LimitKey::WhatsAppOrdersPerMonth));

        // Plan & Usage: the owner's meter.
        $this->placedOrder($shop);
        $this->actingAs($owner)->get(route('billing.index'))->assertSee('WhatsApp orders this month')->assertSee('1 / 85');
    }
}
