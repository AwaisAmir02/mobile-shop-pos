<?php

namespace Tests\Feature;

use App\Enums\WhatsAppOrderStatus;
use App\Enums\WhatsAppPaymentChoice;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppPaymentChoiceTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function account(Shop $shop, string $name, ?string $number, ?string $title = null): ShopAccount
    {
        return ShopAccount::create(['shop_id' => $shop->id, 'name' => $name, 'provider_type' => 'Bank', 'account_title' => $title, 'account_number' => $number]);
    }

    /** A shop, a cart with one Rs 1,500 item, and the bot at the end of the delivery details. */
    protected function botAtPayment(?Shop &$shop = null, bool $pickup = false, string $phoneNumberId = '100200300', ?callable $beforeDetails = null): WhatsAppChatbot
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop('Shop '.$phoneNumberId, $phoneNumberId);
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);

        if ($beforeDetails) {
            $beforeDetails($shop);
        }

        $bot = new WhatsAppChatbot($connection);
        $this->tap($bot, "qty:{$product->id}:1");
        $this->tap($bot, 'checkout');
        $this->tap($bot, $pickup ? 'ship:pickup' : 'ship:delivery');
        $this->type($bot, 'Ali Khan');
        if (! $pickup) {
            $this->type($bot, 'House 5, Street 2');
            $this->type($bot, 'Gulberg');
        }
        $this->tap($bot, 'phone:whatsapp');
        $this->tap($bot, 'save:no');

        return $bot;
    }

    protected function tap(WhatsAppChatbot $bot, string $id): void
    {
        $bot->handleInbound($this->replyMessage($this->customer, $id), 'Ali');
    }

    protected function type(WhatsAppChatbot $bot, string $text): void
    {
        $bot->handleInbound($this->textMessage($this->customer, $text), 'Ali');
    }

    protected function last(): array
    {
        return collect($this->sentBodies())->last();
    }

    protected function lastBody(): string
    {
        $last = $this->last();

        return $last['type'] === 'text' ? $last['text']['body'] : $last['interactive']['body']['text'];
    }

    protected function lastButtons(): array
    {
        return collect($this->last()['interactive']['action']['buttons'] ?? [])->mapWithKeys(fn ($b) => [$b['reply']['id'] => $b['reply']['title']])->all();
    }

    protected function order(Shop $shop): WhatsAppOrder
    {
        return WhatsAppOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    public function test_without_an_account_to_pay_into_the_question_is_skipped_and_cash_is_recorded(): void
    {
        $shop = null;
        $bot = $this->botAtPayment($shop, beforeDetails: fn (Shop $s) => $this->account($s, 'Till only', null));

        $this->assertSame(['confirm' => 'Confirm order', 'clear' => 'Cancel'], $this->lastButtons());
        $this->assertStringContainsString('Payment: Cash on delivery', $this->lastBody());
        $this->assertSame(WhatsAppPaymentChoice::CashOnDelivery, $this->order($shop)->payment_choice);

        $this->tap($bot, 'confirm');
        $this->assertSame(WhatsAppOrderStatus::Pending, $this->order($shop)->status);
        $this->assertSame(WhatsAppPaymentChoice::CashOnDelivery, $this->order($shop)->payment_choice);
    }

    public function test_a_pickup_offers_pay_at_shop_instead_of_cash_on_delivery(): void
    {
        $shop = null;
        $bot = $this->botAtPayment($shop, pickup: true, beforeDetails: fn (Shop $s) => $this->account($s, 'Meezan', '0123456789'));

        $this->assertSame(['pay:cash' => 'Pay at shop', 'pay:online' => 'Pay online'], $this->lastButtons());

        $this->tap($bot, 'pay:cash');
        $this->assertSame(WhatsAppPaymentChoice::PayAtShop, $this->order($shop)->payment_choice);
        $this->assertStringContainsString('Payment: Pay at shop', $this->lastBody());
    }

    public function test_cash_on_delivery_is_recorded_for_a_delivery(): void
    {
        $shop = null;
        $bot = $this->botAtPayment($shop, beforeDetails: fn (Shop $s) => $this->account($s, 'Meezan', '0123456789'));

        $this->assertSame(['pay:cash' => 'Cash on delivery', 'pay:online' => 'Pay online'], $this->lastButtons());
        $this->tap($bot, 'pay:cash');

        $this->assertSame(WhatsAppPaymentChoice::CashOnDelivery, $this->order($shop)->payment_choice);
        $this->assertSame(['confirm' => 'Confirm order', 'clear' => 'Cancel'], $this->lastButtons());
    }

    public function test_online_shows_only_accounts_with_a_number_and_records_the_choice(): void
    {
        $shop = null;
        $bot = $this->botAtPayment($shop, beforeDetails: function (Shop $s) {
            $this->account($s, 'Meezan Bank', 'PK36MEZN0001', 'Ali Mobiles');
            $this->account($s, 'Shop JazzCash', '03001112233');
            $this->account($s, 'Cash drawer', null);
            $this->account($s, 'Blank number', '');
        });

        $this->tap($bot, 'pay:online');

        $accountsMessage = collect($this->sentBodies())->where('type', 'text')->last()['text']['body'];
        $this->assertStringContainsString('Please send Rs 1,500', $accountsMessage);
        $this->assertStringContainsString("*Meezan Bank* (Bank)\nTitle: Ali Mobiles\nAccount: PK36MEZN0001", $accountsMessage);
        $this->assertStringContainsString("*Shop JazzCash* (Bank)\nAccount: 03001112233", $accountsMessage);
        $this->assertStringNotContainsString('Cash drawer', $accountsMessage);
        $this->assertStringNotContainsString('Blank number', $accountsMessage);

        // 2d: the slip question comes next; "Send later" goes on to the confirm step.
        $this->assertSame(['slip:later' => 'Send later'], $this->lastButtons());
        $this->tap($bot, 'slip:later');
        $this->assertStringContainsString('Payment: Online transfer', $this->lastBody());
        $this->assertSame(WhatsAppPaymentChoice::Online, $this->order($shop)->payment_choice);
    }

    public function test_typed_answers_work_and_stray_taps_restart_checkout(): void
    {
        $shop = null;
        $bot = $this->botAtPayment($shop, beforeDetails: fn (Shop $s) => $this->account($s, 'Meezan', '0123456789'));

        $this->type($bot, 'online');
        $this->assertSame(WhatsAppPaymentChoice::Online, $this->order($shop)->payment_choice);

        // An old payment button tapped outside the payment question changes nothing.
        $this->tap($bot, 'checkout');
        $this->assertNull($this->order($shop)->payment_choice, 'Starting checkout again clears the earlier choice.');
        $this->tap($bot, 'pay:cash');
        $this->assertNull($this->order($shop)->payment_choice);
        $this->assertSame(['ship:delivery' => 'Home delivery', 'ship:pickup' => 'Pickup from shop'], $this->lastButtons());
    }

    // ── Staff order screen ────────────────────────────────────────────

    protected function pendingOrder(Shop $shop, ?WhatsAppPaymentChoice $choice): WhatsAppOrder
    {
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '92300'.random_int(1000000, 9999999), 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'delivery_address' => 'House 5', 'payment_choice' => $choice]);
        $product = $this->listedProduct($shop, 'Cable', 500, 5);
        WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 1, 'quoted_unit_price' => 500]);

        return $order;
    }

    public function test_the_order_screen_shows_the_choice_and_prefills_paid_by(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        [$otherShop] = $this->whatsappShop('Shop B', '999');
        $this->actingAs($owner);
        $this->account($otherShop, 'Other shop bank', '999999');

        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, WhatsAppPaymentChoice::CashOnDelivery)])
            ->assertSee('Cash on delivery')
            ->assertSet('paymentMethod', 'cash');
        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, WhatsAppPaymentChoice::PayAtShop)])
            ->assertSee('Pay at shop')
            ->assertSet('paymentMethod', 'cash');
        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, null)])
            ->assertSee('Not chosen')
            ->assertSet('paymentMethod', '');

        // Online, and this shop has no account to pay into (another shop's doesn't count).
        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, WhatsAppPaymentChoice::Online)])
            ->assertSee('Online transfer')
            ->assertSet('paymentMethod', '');

        // Exactly one account to pay into: it can only have gone there.
        $only = $this->account($shop, 'Meezan', '0123456789');
        $this->account($shop, 'Cash drawer', null);
        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, WhatsAppPaymentChoice::Online)])
            ->assertSet('paymentMethod', (string) $only->id);

        // Two: staff choose after checking which one received it.
        $this->account($shop, 'JazzCash', '03001112233');
        Livewire::test('whatsapp-orders.show', ['order' => $this->pendingOrder($shop, WhatsAppPaymentChoice::Online)])
            ->assertSet('paymentMethod', '');
    }

    // ── Settings ──────────────────────────────────────────────────────

    public function test_settings_saves_and_clears_the_account_title_and_number(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openShopAccountCreate')
            ->set('shopAccountName', 'Meezan Bank')
            ->set('shopAccountProviderType', 'Bank')
            ->set('shopAccountTitle', ' Ali Mobiles ')
            ->set('shopAccountNumber', 'PK36MEZN0001')
            ->call('saveShopAccount')
            ->assertHasNoErrors();

        $account = ShopAccount::sole();
        $this->assertSame(['Ali Mobiles', 'PK36MEZN0001'], [$account->account_title, $account->account_number]);

        Livewire::test('settings.index', ['tab' => 'shop-accounts'])
            ->call('openShopAccountEdit', $account->id)
            ->assertSet('shopAccountNumber', 'PK36MEZN0001')
            ->set('shopAccountTitle', '')
            ->set('shopAccountNumber', '  ')
            ->call('saveShopAccount')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertNull($account->account_title);
        $this->assertNull($account->account_number);
        $this->assertFalse($account->hasPaymentDetails());
    }

    // ── Cross-shop ────────────────────────────────────────────────────

    public function test_another_shops_accounts_are_never_offered_or_shown(): void
    {
        [$otherShop] = $this->whatsappShop('Shop B', '999');
        $this->account($otherShop, 'Secret B Bank', 'B-ACCOUNT-1');

        $shop = null;
        $this->botAtPayment($shop, phoneNumberId: '444');

        // Shop A has nothing to pay into, so it isn't even asked — B's account doesn't count.
        $this->assertSame(['confirm' => 'Confirm order', 'clear' => 'Cancel'], $this->lastButtons());
        $this->assertStringNotContainsString('Secret B', json_encode($this->sentBodies()));
        $this->assertStringNotContainsString('B-ACCOUNT', json_encode($this->sentBodies()));

        $shopC = null;
        $bot = $this->botAtPayment($shopC, phoneNumberId: '555', beforeDetails: fn (Shop $s) => $this->account($s, 'C Bank', 'C-1'));
        $this->tap($bot, 'pay:online');
        $sent = json_encode($this->sentBodies());
        $this->assertStringContainsString('C-1', $sent);
        $this->assertStringNotContainsString('B-ACCOUNT', $sent);
    }
}
