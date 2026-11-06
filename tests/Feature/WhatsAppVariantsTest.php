<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RestockSubscription;
use App\Models\Shop;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrderItem;
use App\Services\ProductGroupService;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppVariantsTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    /** @return array{0: ProductGroup, 1: \Illuminate\Support\Collection} */
    protected function listedGroup(Shop $shop, string $name, array $labels, int $stock = 5): array
    {
        $service = app(ProductGroupService::class);
        $group = $service->createGroup($shop->id, $name, 'accessory', '');
        $service->addVariants($group, $labels, 1000, null);
        $group->variants()->update(['whatsapp_listed' => true, 'stock_quantity' => $stock]);

        return [$group, $group->variants()->get()];
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

    protected function fakeGraph(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
    }

    /** @return array<int, array> rows of the last list message */
    protected function lastRows(): array
    {
        $list = collect($this->sentBodies())->where('interactive.type', 'list')->last();

        return $list ? $list['interactive']['action']['sections'][0]['rows'] : [];
    }

    public function test_the_catalogue_shows_a_group_once_and_counts_it_as_one_item(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group] = $this->listedGroup($shop, 'Cotton Shirt', ['S', 'M', 'L']);
        $plain = $this->listedProduct($shop, 'Charger');
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'browse:page:1');

        $rows = $this->lastRows();
        $this->assertSame(['product:'.$plain->id, 'group:'.$group->id], array_column($rows, 'id'));
        $this->assertSame('Cotton Shirt', $rows[1]['title']);
        $this->assertStringContainsString('From Rs 1,000', $rows[1]['description']);
        $this->assertStringContainsString('Options: 3', $rows[1]['description']);
    }

    public function test_the_picker_shows_only_the_label_so_it_survives_the_title_limit(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group, $variants] = $this->listedGroup($shop, 'Premium Cotton Oxford Shirt', ['M / Red', 'M / Blue']);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);

        $rows = $this->lastRows();
        $this->assertSame(['M / Red', 'M / Blue'], array_column($rows, 'title'));
        $this->assertSame('product:'.$variants[0]->id, $rows[0]['id']);
        $this->assertStringContainsString('5 in stock', $rows[0]['description']);
    }

    public function test_a_label_too_long_for_a_title_repeats_in_the_description(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $long = 'Extra Large / Midnight Blue Stripe';
        [$group] = $this->listedGroup($shop, 'Shirt', ['S', $long]);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);

        $rows = $this->lastRows();
        $this->assertLessThanOrEqual(24, mb_strlen($rows[1]['title']));
        $this->assertStringContainsString($long, $rows[1]['description']);
    }

    public function test_choosing_a_variant_reaches_the_cart_as_that_variant(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group, $variants] = $this->listedGroup($shop, 'Cotton Shirt', ['S', 'M']);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);
        $this->reply($bot, 'product:'.$variants[1]->id);
        $this->reply($bot, 'qty:'.$variants[1]->id.':2');

        $item = WhatsAppOrderItem::withoutGlobalScopes()->sole();
        $this->assertSame($variants[1]->id, $item->product_id);
        $this->assertSame('Cotton Shirt - M', $item->product_name);
        $this->assertSame(2, $item->quantity);
    }

    public function test_a_long_group_pages_through_its_variants(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group] = $this->listedGroup($shop, 'Shirt', array_map(fn (int $i) => "Option {$i}", range(1, 12)));
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);
        $rows = $this->lastRows();
        $this->assertCount(WhatsAppChatbot::PAGE_SIZE + 1, $rows);
        $this->assertSame('group:'.$group->id.':2', end($rows)['id']);

        $this->reply($bot, 'group:'.$group->id.':2');
        $this->assertSame(['Option 10', 'Option 11', 'Option 12'], array_column($this->lastRows(), 'title'));
    }

    public function test_an_out_of_stock_variant_stays_listed_and_offers_the_restock_alert(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group, $variants] = $this->listedGroup($shop, 'Shirt', ['S', 'M']);
        $variants[0]->update(['stock_quantity' => 0]);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);
        $this->assertStringContainsString('Out of stock', $this->lastRows()[0]['description']);

        $this->reply($bot, 'notify:'.$variants[0]->id);
        $this->assertSame($variants[0]->id, RestockSubscription::withoutGlobalScopes()->sole()->product_id);
    }

    public function test_only_one_listed_variant_skips_the_picker(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group, $variants] = $this->listedGroup($shop, 'Shirt', ['S', 'M']);
        $variants[1]->update(['whatsapp_listed' => false]);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);

        $this->assertSame([], $this->lastRows());
        $this->assertSame('awaiting_quantity', WhatsAppContact::withoutGlobalScopes()->sole()->bot_state['step']);
        $this->assertSame($variants[0]->id, WhatsAppContact::withoutGlobalScopes()->sole()->bot_state['product_id']);
    }

    public function test_search_collapses_a_group_and_goes_straight_to_one_exact_variant(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group, $variants] = $this->listedGroup($shop, 'Cotton Shirt', ['Small', 'Medium']);
        $socks = $this->listedProduct($shop, 'Cotton Socks');
        $bot = new WhatsAppChatbot($connection);

        $this->type($bot, 'cotton');
        $ids = array_column($this->lastRows(), 'id');
        $this->assertContains('group:'.$group->id, $ids);
        $this->assertContains('product:'.$socks->id, $ids);
        $this->assertCount(2, $ids);

        $this->type($bot, 'shirt');
        $this->assertSame(['Small', 'Medium'], array_column($this->lastRows(), 'title'));

        $this->type($bot, 'shirt medium');
        $this->assertSame($variants[1]->id, WhatsAppContact::withoutGlobalScopes()->sole()->bot_state['product_id']);
    }

    public function test_another_shops_group_is_not_reachable(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$other] = $this->whatsappShop('Shop B', '999888777');
        [$foreign] = $this->listedGroup($other, 'Foreign', ['S', 'M']);
        $this->listedProduct($shop, 'Charger');
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$foreign->id);

        $this->assertNotContains('Foreign', array_column($this->lastRows(), 'title'));
        $this->assertNotContains('S', array_column($this->lastRows(), 'title'));
    }

    public function test_urdu_has_the_same_variant_strings(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$group] = $this->listedGroup($shop, 'Shirt', ['S', 'M']);
        WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => $this->customer, 'language' => 'ur']);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'group:'.$group->id);

        $list = collect($this->sentBodies())->where('interactive.type', 'list')->last();
        $this->assertStringContainsString('option chunein', $list['interactive']['body']['text']);
    }

    public function test_a_shop_with_no_groups_gets_the_same_catalogue_rows(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $a = $this->listedProduct($shop, 'Alpha');
        $b = $this->listedProduct($shop, 'Beta', stock: 0);
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'browse:page:1');
        $this->assertSame([], array_diff(array_column($this->lastRows(), 'id'), ['product:'.$a->id]));
        $this->assertNotContains('product:'.$b->id, array_column($this->lastRows(), 'id'));
    }
}
