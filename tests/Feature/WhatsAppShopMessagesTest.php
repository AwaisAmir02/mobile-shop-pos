<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\WhatsAppChatbot;
use App\Support\WhatsAppMessageSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppShopMessagesTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function welcomeText(): string
    {
        return collect($this->sentBodies())->where('interactive.type', 'button')->last()['interactive']['body']['text'];
    }

    protected function lastPlainText(): string
    {
        return collect($this->sentBodies())->where('type', 'text')->last()['text']['body'];
    }

    protected function placeOrder(WhatsAppChatbot $bot, Shop $shop): WhatsAppOrder
    {
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$product->id}:1"), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'checkout'), 'Ali');
        $bot->handleInbound($this->textMessage($this->customer, 'pickup'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'confirm'), 'Ali');

        return WhatsAppOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    public function test_without_custom_text_the_standard_messages_are_sent_unchanged(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'hi'), 'Ali');
        $this->assertSame(__('whatsapp.welcome', ['shop' => $shop->invoiceDisplayName()], 'en'), $this->welcomeText());

        $order = $this->placeOrder($bot, $shop);
        $this->assertSame(__('whatsapp.order_received', ['order' => $order->orderNumber()], 'en'), $this->lastPlainText());
    }

    public function test_custom_welcome_and_thank_you_are_used_per_language_with_tokens_filled(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $shop->update(['whatsapp_messages' => [
            'welcome' => ['en' => 'Hello from {shop}!', 'ur' => '{shop} mein aayiye!'],
            'order_received' => ['en' => 'Got {order} at {shop}, thanks.'],
        ]]);
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'hi'), 'Ali');
        $this->assertSame('Hello from Shop A!', $this->welcomeText());
        $this->assertSame(['browse', 'cart', 'agent'], array_map(fn ($b) => $b['reply']['id'], collect($this->sentBodies())->last()['interactive']['action']['buttons']));

        $order = $this->placeOrder($bot, $shop);
        $this->assertSame("Got {$order->orderNumber()} at Shop A, thanks.", $this->lastPlainText());

        // Urdu: custom welcome; no Urdu thank-you set, so the standard Urdu text is used.
        WhatsAppContact::withoutGlobalScopes()->update(['language' => 'ur']);
        $bot = new WhatsAppChatbot($connection);
        $bot->handleInbound($this->textMessage($this->customer, 'menu'), 'Ali');
        $this->assertSame('Shop A mein aayiye!', $this->welcomeText());

        $order = $this->placeOrder($bot, $shop);
        $this->assertSame(__('whatsapp.order_received', ['order' => $order->orderNumber()], 'ur'), $this->lastPlainText());
    }

    public function test_settings_editor_saves_suggestions_custom_text_and_blank_means_standard(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $this->actingAs($owner);
        $suggestions = WhatsAppMessageSuggestions::all();

        $this->get(route('settings.index', ['tab' => 'integrations']))->assertOk()->assertSee('WhatsApp chatbot messages');

        Livewire::test('settings.whatsapp-messages')
            ->assertSet('texts.welcome.en', '')
            ->call('useSuggestion', 'welcome', 'en', 1)
            ->assertSet('texts.welcome.en', $suggestions['welcome'][1]['en'])
            ->call('useSuggestion', 'order_received', 'ur', 2)
            ->set('texts.order_received.en', '  Thanks for order {order}!  ')
            ->call('save')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame($suggestions['welcome'][1]['en'], $shop->whatsappMessage('welcome', 'en'));
        $this->assertNull($shop->whatsappMessage('welcome', 'ur'));
        $this->assertSame($suggestions['order_received'][2]['ur'], $shop->whatsappMessage('order_received', 'ur'));
        $this->assertSame('Thanks for order {order}!', $shop->whatsappMessage('order_received', 'en'));

        // Clearing every box goes back to the standard text everywhere.
        Livewire::test('settings.whatsapp-messages')
            ->assertSet('texts.order_received.en', 'Thanks for order {order}!')
            ->call('useDefault', 'welcome', 'en')
            ->set('texts.order_received.en', '')
            ->set('texts.order_received.ur', '')
            ->call('save');

        $this->assertNull($shop->fresh()->whatsapp_messages);
    }

    public function test_messages_are_length_limited_and_the_editor_needs_whatsapp_and_permission(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $this->actingAs($owner);

        Livewire::test('settings.whatsapp-messages')
            ->set('texts.welcome.en', str_repeat('a', WhatsAppMessageSuggestions::MAX_LENGTH + 1))
            ->call('save')
            ->assertHasErrors(['texts.welcome.en']);
        $this->assertNull($shop->fresh()->whatsapp_messages);

        Livewire::test('settings.whatsapp-messages')->call('useSuggestion', 'refund_policy', 'en', 0)->assertNotFound();

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));
        Livewire::test('settings.whatsapp-messages')->assertForbidden();

        $shop->update(['whatsapp_enabled' => false]);
        $this->actingAs($owner->fresh());
        Livewire::test('settings.whatsapp-messages')->assertForbidden();
    }

    // ── Cross-shop ────────────────────────────────────────────────────

    public function test_one_shops_messages_never_reach_another_shops_customers_or_settings(): void
    {
        $this->fakeGraph();
        [$shopA, $ownerA, $connectionA] = $this->whatsappShop('Shop A', '444');
        [$shopB, , $connectionB] = $this->whatsappShop('Shop B', '555');
        $shopB->update(['whatsapp_messages' => ['welcome' => ['en' => 'SECRET B WELCOME']]]);

        (new WhatsAppChatbot($connectionA))->handleInbound($this->textMessage($this->customer, 'hi'), 'Ali');
        $this->assertSame(__('whatsapp.welcome', ['shop' => 'Shop A'], 'en'), $this->welcomeText());

        (new WhatsAppChatbot($connectionB))->handleInbound($this->textMessage($this->customer, 'hi'), 'Ali');
        $this->assertSame('SECRET B WELCOME', $this->welcomeText());

        // Shop A's editor shows and saves only Shop A's text.
        $this->actingAs($ownerA);
        Livewire::test('settings.whatsapp-messages')
            ->assertSet('texts.welcome.en', '')
            ->assertDontSee('SECRET B WELCOME')
            ->set('texts.welcome.en', 'A only')
            ->call('save');

        $this->assertSame('A only', $shopA->fresh()->whatsappMessage('welcome', 'en'));
        $this->assertSame('SECRET B WELCOME', $shopB->fresh()->whatsappMessage('welcome', 'en'));
    }
}
