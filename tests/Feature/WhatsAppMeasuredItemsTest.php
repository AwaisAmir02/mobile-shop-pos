<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\WhatsAppOrderItem;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppMeasuredItemsTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function fakeGraph(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
    }

    protected function reply(WhatsAppChatbot $bot, string $id): void
    {
        $this->fakeGraph();
        $bot->handleInbound($this->replyMessage($this->customer, $id, 'list_reply'), null);
    }

    protected function type(WhatsAppChatbot $bot, string $text): void
    {
        $this->fakeGraph();
        $bot->handleInbound($this->textMessage($this->customer, $text), null);
    }

    /** A weighed product marked listed behind the model's back, as bad data or a raw update could. */
    protected function weighed($shop): Product
    {
        $product = $this->listedProduct($shop, 'Basmati Rice');
        Product::whereKey($product->id)->update(['unit' => 'kg']);

        return $product->fresh();
    }

    public function test_weighed_items_stay_out_of_the_catalogue_search_and_cart(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $rice = $this->weighed($shop);
        $cable = $this->listedProduct($shop, 'Cable');
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'browse:page:1');
        $rows = collect($this->sentBodies())->where('interactive.type', 'list')->last()['interactive']['action']['sections'][0]['rows'];
        $this->assertSame(['product:'.$cable->id], array_column($rows, 'id'));

        $this->reply($bot, 'product:'.$rice->id);
        $this->reply($bot, 'qty:'.$rice->id.':1');

        $this->assertSame(0, WhatsAppOrderItem::withoutGlobalScopes()->count());
    }

    public function test_a_piece_order_works_exactly_as_before_and_records_its_unit(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $cable = $this->listedProduct($shop, 'Cable');
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'product:'.$cable->id);
        $this->type($bot, '2');

        $item = WhatsAppOrderItem::withoutGlobalScopes()->first();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('piece', $item->unit->value);
    }

    public function test_typing_a_fraction_at_the_quantity_step_is_a_quantity_not_a_search(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $cable = $this->listedProduct($shop, 'Cable');
        // Would match a search for "1.5" if the text were treated as one.
        $this->listedProduct($shop, 'Adapter 1.5 metre');
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'product:'.$cable->id);
        $this->type($bot, '1.5');

        $this->assertSame(0, WhatsAppOrderItem::withoutGlobalScopes()->count());

        $last = collect($this->sentBodies())->last();
        $this->assertStringContainsString(__('whatsapp.qty_whole', [], 'en'), $last['text']['body']);

        // Still on the quantity step, so a whole number now goes through.
        $this->type($bot, '2');
        $this->assertSame(2, WhatsAppOrderItem::withoutGlobalScopes()->first()->quantity);
    }

    public function test_urdu_has_the_whole_number_string(): void
    {
        $this->assertNotSame('whatsapp.qty_whole', __('whatsapp.qty_whole', [], 'ur'));
    }
}
