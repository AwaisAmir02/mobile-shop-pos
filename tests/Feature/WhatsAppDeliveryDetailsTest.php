<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppDeliveryDetailsTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function cartBot(?Shop &$shop = null, ?string $phoneNumberId = null): WhatsAppChatbot
    {
        $this->fakeGraph();
        [$shop, , $connection] = $phoneNumberId ? $this->whatsappShop('Shop '.$phoneNumberId, $phoneNumberId) : $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);
        $bot = new WhatsAppChatbot($connection);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$product->id}:1"), 'Ali WA');

        return $bot;
    }

    protected function tap(WhatsAppChatbot $bot, string $id): void
    {
        $bot->handleInbound($this->replyMessage($this->customer, $id), 'Ali WA');
    }

    protected function type(WhatsAppChatbot $bot, string $text): void
    {
        $bot->handleInbound($this->textMessage($this->customer, $text), 'Ali WA');
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
        return array_map(fn ($b) => $b['reply']['id'], $this->last()['interactive']['action']['buttons'] ?? []);
    }

    protected function contact(?Shop $shop = null): WhatsAppContact
    {
        return WhatsAppContact::withoutGlobalScopes()->when($shop, fn ($q) => $q->where('shop_id', $shop->id))->where('wa_id', $this->customer)->sole();
    }

    protected function order(?Shop $shop = null): WhatsAppOrder
    {
        return WhatsAppOrder::withoutGlobalScopes()->when($shop, fn ($q) => $q->where('shop_id', $shop->id))->latest('id')->firstOrFail();
    }

    protected function enterDelivery(WhatsAppChatbot $bot, string $name = 'Ali Khan', string $address = 'House 5, Street 2', string $area = 'Model Town', string $phone = '0300 1234567'): void
    {
        $this->tap($bot, 'ship:delivery');
        $this->type($bot, $name);
        $this->type($bot, $address);
        $this->type($bot, $area);
        $this->type($bot, $phone);
    }

    public function test_first_time_delivery_collects_each_detail_then_asks_before_saving(): void
    {
        $bot = $this->cartBot();

        $this->tap($bot, 'checkout');
        $this->assertSame(['ship:delivery', 'ship:pickup'], $this->lastButtons());

        $this->tap($bot, 'ship:delivery');
        $this->assertSame('What name should we put on this order?', $this->lastBody());
        $this->type($bot, 'Ali Khan');
        $this->assertSame('Please send your delivery address (house, street).', $this->lastBody());
        $this->type($bot, 'House 5, Street 2');
        $this->assertStringStartsWith('Which area', $this->lastBody());
        $this->type($bot, 'Model Town');
        $this->assertSame(['phone:whatsapp'], $this->lastButtons());
        $this->type($bot, '0300 1234567');

        $this->assertSame('Save these details for your next order?', $this->lastBody());
        $this->assertSame(['save:yes', 'save:no'], $this->lastButtons());
        $this->assertNull($this->contact()->delivery_name, 'Nothing is saved before the customer agrees.');

        $this->tap($bot, 'save:yes');
        $this->assertSame(['confirm', 'clear'], $this->lastButtons());
        $this->assertStringContainsString("Name: Ali Khan\nPhone: 0300 1234567\nDelivery: House 5, Street 2, Model Town", $this->lastBody());

        $this->tap($bot, 'confirm');

        $order = $this->order();
        $this->assertSame(WhatsAppOrderStatus::Pending, $order->status);
        $this->assertSame(['Ali Khan', 'House 5, Street 2', 'Model Town', '0300 1234567'], [$order->delivery_name, $order->delivery_address, $order->delivery_area, $order->delivery_phone]);

        $contact = $this->contact();
        $this->assertSame(['Ali Khan', 'House 5, Street 2', 'Model Town', '0300 1234567'], [$contact->delivery_name, $contact->delivery_address, $contact->delivery_area, $contact->delivery_phone]);
    }

    public function test_declining_to_save_keeps_the_details_on_the_order_only(): void
    {
        $bot = $this->cartBot();
        $this->tap($bot, 'checkout');
        $this->enterDelivery($bot);
        $this->tap($bot, 'save:no');
        $this->tap($bot, 'confirm');

        $this->assertSame('Ali Khan', $this->order()->delivery_name);
        $this->assertFalse($this->contact()->hasSavedDelivery());
    }

    public function test_saved_details_are_shown_and_never_used_until_the_customer_chooses(): void
    {
        $bot = $this->cartBot();
        $this->contact()->update(['delivery_name' => 'Ali Khan', 'delivery_address' => 'House 5', 'delivery_area' => 'Gulberg', 'delivery_phone' => '03001234567']);

        $this->tap($bot, 'checkout');

        $this->assertSame(['details:use', 'ship:pickup', 'details:update'], $this->lastButtons());
        $this->assertStringContainsString("Name: Ali Khan\nPhone: 03001234567\nDelivery: House 5, Gulberg", $this->lastBody());
        $order = $this->order();
        $this->assertNull($order->delivery_address);
        $this->assertNull($order->delivery_name);

        // Confirm tapped without choosing: checkout starts again, nothing is submitted.
        $this->tap($bot, 'confirm');
        $this->assertSame(WhatsAppOrderStatus::Collecting, $this->order()->status);
        $this->assertSame(['details:use', 'ship:pickup', 'details:update'], $this->lastButtons());

        $this->tap($bot, 'details:use');
        $this->assertSame(['confirm', 'clear'], $this->lastButtons(), 'Reusing saved details asks no save question.');
        $this->tap($bot, 'confirm');

        $order = $this->order();
        $this->assertSame(WhatsAppOrderStatus::Pending, $order->status);
        $this->assertSame(['Ali Khan', 'House 5', 'Gulberg', '03001234567'], [$order->delivery_name, $order->delivery_address, $order->delivery_area, $order->delivery_phone]);
    }

    public function test_updating_saved_details_asks_before_replacing_them(): void
    {
        $bot = $this->cartBot();
        $this->contact()->update(['delivery_name' => 'Old Name', 'delivery_address' => 'Old House', 'delivery_area' => 'Old Area', 'delivery_phone' => '03000000000']);

        $this->tap($bot, 'checkout');
        $this->tap($bot, 'details:update');
        $this->assertSame(['ship:delivery', 'ship:pickup'], $this->lastButtons());
        $this->enterDelivery($bot, 'New Name', 'New House 9', 'DHA', '03110000000');

        $this->assertStringContainsString('replaces your saved details', $this->lastBody());
        $this->assertSame('Old Name', $this->contact()->delivery_name);

        $this->tap($bot, 'save:yes');
        $this->assertSame(['New Name', 'New House 9', 'DHA', '03110000000'], [$this->contact()->delivery_name, $this->contact()->delivery_address, $this->contact()->delivery_area, $this->contact()->delivery_phone]);
    }

    public function test_pickup_asks_name_and_phone_only_and_saving_keeps_the_saved_address(): void
    {
        $bot = $this->cartBot();
        $this->contact()->update(['delivery_address' => 'House 5', 'delivery_area' => 'Gulberg']);

        $this->tap($bot, 'checkout');
        $this->tap($bot, 'details:update');
        $this->tap($bot, 'ship:pickup');
        $this->assertSame('What name should we put on this order?', $this->lastBody());
        $this->type($bot, 'Sara');
        $this->assertSame(['phone:whatsapp'], $this->lastButtons());
        $this->tap($bot, 'phone:whatsapp');
        $this->tap($bot, 'save:yes');
        $this->assertStringContainsString("Name: Sara\nPhone: +923001234567\nDelivery: Pickup from shop", $this->lastBody());
        $this->tap($bot, 'confirm');

        $order = $this->order();
        $this->assertSame(['Sara', 'Pickup from shop', null, '+923001234567'], [$order->delivery_name, $order->delivery_address, $order->delivery_area, $order->delivery_phone]);
        $this->assertTrue($order->isPickup());

        $contact = $this->contact();
        $this->assertSame(['Sara', '+923001234567', 'House 5', 'Gulberg'], [$contact->delivery_name, $contact->delivery_phone, $contact->delivery_address, $contact->delivery_area]);
    }

    public function test_pickup_from_the_saved_details_message_reuses_the_shown_name_and_phone(): void
    {
        $bot = $this->cartBot();
        $this->contact()->update(['delivery_name' => 'Ali Khan', 'delivery_phone' => '03001234567', 'delivery_address' => 'House 5']);

        $this->tap($bot, 'checkout');
        $this->tap($bot, 'ship:pickup');

        $this->assertSame(['confirm', 'clear'], $this->lastButtons());
        $this->tap($bot, 'confirm');
        $this->assertSame(['Ali Khan', '03001234567', 'Pickup from shop'], [$this->order()->delivery_name, $this->order()->delivery_phone, $this->order()->delivery_address]);
    }

    public function test_invalid_answers_are_asked_again_and_a_typed_address_starts_a_delivery(): void
    {
        $bot = $this->cartBot();
        $this->tap($bot, 'checkout');

        // Typing an address at the delivery/pickup question picks delivery with that address.
        $this->type($bot, 'Flat 3, Block B');
        $this->assertSame('What name should we put on this order?', $this->lastBody());
        $askedForName = collect($this->sentBodies())->filter(fn ($b) => ($b['text']['body'] ?? null) === 'What name should we put on this order?')->count();
        $this->assertSame(1, $askedForName);

        $this->type($bot, 'A');
        $this->assertStringStartsWith('Please send a name', $this->lastBody());
        $this->type($bot, 'Ali');
        $this->assertStringStartsWith('Which area', $this->lastBody());
        $this->type($bot, 'Johar Town');
        $this->type($bot, 'call me');
        $this->assertStringStartsWith("That doesn't look like a phone number", $this->lastBody());
        $this->type($bot, '03001234567');
        $this->assertSame(['save:yes', 'save:no'], $this->lastButtons());
        $this->assertSame('Flat 3, Block B', $this->order()->delivery_address);
    }

    public function test_saving_fills_blank_fields_on_the_linked_customer_without_overwriting(): void
    {
        $shop = null;
        $bot = $this->cartBot($shop);
        $blank = Customer::create(['shop_id' => $shop->id, 'name' => 'Staff Name']);
        $this->contact()->update(['customer_id' => $blank->id]);

        $this->tap($bot, 'checkout');
        $this->enterDelivery($bot, 'Chat Name', 'House 5', 'Gulberg', '03211234567');
        $this->tap($bot, 'save:yes');

        $blank->refresh();
        $this->assertSame('Staff Name', $blank->name);
        $this->assertSame('House 5, Gulberg', $blank->address);
        $this->assertSame('03211234567', $blank->phone);

        $filled = Customer::create(['shop_id' => $shop->id, 'name' => 'Kept', 'phone' => '03009999999', 'address' => 'Kept address']);
        $contact = $this->contact();
        $contact->update(['customer_id' => $filled->id]);
        $contact->syncDeliveryToCustomer();

        $this->assertSame(['Kept', '03009999999', 'Kept address'], [$filled->fresh()->name, $filled->fresh()->phone, $filled->fresh()->address]);
    }

    public function test_saved_details_are_copied_when_the_contact_gets_linked_later(): void
    {
        $shop = null;
        $bot = $this->cartBot($shop);
        $this->contact()->update(['delivery_name' => 'Ali', 'delivery_address' => 'House 5', 'delivery_area' => 'Gulberg', 'delivery_phone' => '03211234567']);

        // Linked by phone on the next message.
        $byPhone = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali', 'phone' => '0300-1234567']);
        $this->type($bot, 'hi');
        $this->assertSame($byPhone->id, $this->contact()->customer_id);
        $this->assertSame('House 5, Gulberg', $byPhone->fresh()->address);

        // Linked when staff accept an order for a chosen customer.
        Bus::fake();
        $other = $this->whatsappShop('Shop C', '777');
        $product = $this->listedProduct($other[0], 'Cable', 500, 5);
        $contact = WhatsAppContact::create(['shop_id' => $other[0]->id, 'wa_id' => '923331112222', 'last_inbound_at' => now(), 'delivery_address' => 'Shop C street', 'delivery_area' => 'Saddar']);
        $order = WhatsAppOrder::create(['shop_id' => $other[0]->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now()]);
        $chosen = Customer::create(['shop_id' => $other[0]->id, 'name' => 'Walk-in Wali']);
        $this->actingAs($other[1]);

        AcceptWhatsAppOrder::handle($order, [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => 500.0]], 0, $chosen->id, 500, $other[1]->id);

        $this->assertSame('Shop C street, Saddar', $chosen->fresh()->address);
    }

    public function test_the_order_screen_shows_the_details_and_creates_the_customer_from_them(): void
    {
        $shop = null;
        $bot = $this->cartBot($shop);
        $this->tap($bot, 'checkout');
        $this->enterDelivery($bot, 'Ali Khan', 'House 5', 'Gulberg', '03211234567');
        $this->tap($bot, 'save:no');
        $this->tap($bot, 'confirm');
        $order = $this->order();
        $owner = \App\Models\User::where('shop_id', $shop->id)->firstOrFail();
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->assertSee('Ali Khan')
            ->assertSee('03211234567')
            ->assertSee('Gulberg')
            ->call('createCustomerFromContact');

        $customer = Customer::where('name', 'Ali Khan')->sole();
        $this->assertSame('+923001234567', $customer->phone);
        $this->assertSame('House 5, Gulberg', $customer->address);
    }

    // ── Cross-shop isolation ──────────────────────────────────────────

    public function test_another_shops_saved_details_and_customers_are_never_shown_or_touched(): void
    {
        $shopB = null;
        $botB = $this->cartBot($shopB, '555');
        $this->contact($shopB)->update(['delivery_name' => 'Secret B', 'delivery_address' => 'B Street', 'delivery_phone' => '03005555555']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'B Customer']);

        $shopA = null;
        $botA = $this->cartBot($shopA, '444');

        $this->tap($botA, 'checkout');
        $this->assertSame(['ship:delivery', 'ship:pickup'], $this->lastButtons());
        $this->assertStringNotContainsString('Secret B', json_encode($this->sentBodies()));

        // Even a contact wrongly pointing at another shop's customer can't write to it.
        $contactA = $this->contact($shopA);
        $contactA->forceFill(['customer_id' => $customerB->id, 'delivery_address' => 'A Street'])->save();
        $contactA->syncDeliveryToCustomer();
        $this->assertNull($customerB->fresh()->address);

        $this->enterDelivery($botA);
        $this->tap($botA, 'save:yes');
        $this->assertSame('Secret B', $this->contact($shopB)->delivery_name);
        $this->assertSame('B Street', $this->contact($shopB)->delivery_address);
    }
}
