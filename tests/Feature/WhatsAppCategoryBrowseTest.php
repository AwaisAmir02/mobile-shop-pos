<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppCategoryBrowseTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function category(Shop $shop, string $name, ?string $slug = null): MainCategory
    {
        return MainCategory::create(['shop_id' => $shop->id, 'name' => $name, 'slug' => $slug ?? MainCategory::slugFor($name)]);
    }

    protected function subCategory(MainCategory $category, string $name): AccessoryCategoryOption
    {
        return AccessoryCategoryOption::create(['shop_id' => $category->shop_id, 'main_category_id' => $category->id, 'name' => $name]);
    }

    protected function item(Shop $shop, string $name, string $type, ?string $subCategory = null, int $stock = 5, bool $listed = true): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'type' => $type,
            'name' => $name,
            'price' => 1000,
            'stock_quantity' => $stock,
            'details' => ['category' => $subCategory],
            'whatsapp_listed' => $listed,
        ]);
    }

    protected function send(WhatsAppChatbot $bot, string $replyId): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-1'], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ]);
        $bot->handleInbound($this->replyMessage($this->customer, $replyId, 'list_reply'), null);
    }

    /** @return array<int, string> row ids of the last list message */
    protected function lastListIds(): array
    {
        $list = collect($this->sentBodies())->where('interactive.type', 'list')->last();

        return $list ? array_column($list['interactive']['action']['sections'][0]['rows'], 'id') : [];
    }

    protected function lastText(): ?string
    {
        return collect($this->sentBodies())->where('type', 'text')->last()['text']['body'] ?? null;
    }

    public function test_without_any_categories_browse_falls_back_to_the_flat_product_list(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $a = $this->item($shop, 'Alpha', 'accessory');
        $b = $this->item($shop, 'Beta', 'mobile');

        $this->send(new WhatsAppChatbot($connection), 'browse');

        $this->assertSame(['product:'.$a->id, 'product:'.$b->id], $this->lastListIds());
    }

    public function test_browse_lists_only_categories_with_listed_in_stock_products_plus_all_products(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $phones = $this->category($shop, 'Mobile Phone', 'mobile');
        $accessories = $this->category($shop, 'Accessory', 'accessory');
        $empty = $this->category($shop, 'Watches');
        $this->category($shop, 'Hidden Only');
        $this->item($shop, 'A15', 'mobile');
        $this->item($shop, 'Cable', 'accessory');
        $this->item($shop, 'Sold out watch', 'watches', stock: 0);
        $this->item($shop, 'Unlisted thing', 'hidden-only', listed: false);

        $this->send(new WhatsAppChatbot($connection), 'browse');

        $this->assertSame(['browse:page:1', 'cat:'.$accessories->id, 'cat:'.$phones->id], $this->lastListIds());
        $this->assertNotContains('cat:'.$empty->id, $this->lastListIds());
    }

    public function test_a_category_with_sub_categories_offers_all_each_sub_category_and_other(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $accessories = $this->category($shop, 'Accessory', 'accessory');
        $this->category($shop, 'Mobile Phone', 'mobile');
        $chargers = $this->subCategory($accessories, 'Charger');
        $cables = $this->subCategory($accessories, 'Cable');
        $unused = $this->subCategory($accessories, 'Power Bank');
        $this->item($shop, 'Fast charger', 'accessory', 'Charger');
        $this->item($shop, 'Car charger', 'accessory', 'Charger');
        $this->item($shop, 'Type-C cable', 'accessory', 'Cable');
        $this->item($shop, 'No sub', 'accessory', null);
        $this->item($shop, 'Renamed sub', 'accessory', 'Old Name');
        $this->item($shop, 'A15', 'mobile');

        $this->send(new WhatsAppChatbot($connection), 'cat:'.$accessories->id);

        $this->assertSame([
            'list:c'.$accessories->id.':1',
            'list:s'.$cables->id.':1',
            'list:s'.$chargers->id.':1',
            'list:o'.$accessories->id.':1',
        ], $this->lastListIds());
        $this->assertNotContains('list:s'.$unused->id.':1', $this->lastListIds());

        $rows = collect($this->sentBodies())->where('interactive.type', 'list')->last()['interactive']['action']['sections'][0]['rows'];
        $this->assertSame(['Items: 5', 'Items: 1', 'Items: 2', 'Items: 2'], array_column($rows, 'description'));
    }

    public function test_sub_category_and_other_lists_match_by_exact_text_within_the_category(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $accessories = $this->category($shop, 'Accessory', 'accessory');
        $phones = $this->category($shop, 'Mobile Phone', 'mobile');
        $chargers = $this->subCategory($accessories, 'Charger');
        $fast = $this->item($shop, 'Fast charger', 'accessory', 'Charger');
        $lower = $this->item($shop, 'Lowercase', 'accessory', 'charger');
        $none = $this->item($shop, 'No sub', 'accessory', null);
        $phoneWithSameText = $this->item($shop, 'Phone tagged Charger', 'mobile', 'Charger');
        $bot = new WhatsAppChatbot($connection);

        $this->send($bot, 'list:s'.$chargers->id.':1');
        $this->assertSame(['product:'.$fast->id], $this->lastListIds());

        $this->send($bot, 'list:o'.$accessories->id.':1');
        $this->assertSame(['product:'.$lower->id, 'product:'.$none->id], $this->lastListIds());

        $this->send($bot, 'list:c'.$phones->id.':1');
        $this->assertSame(['product:'.$phoneWithSameText->id], $this->lastListIds());
    }

    public function test_a_category_without_sub_categories_goes_straight_to_its_products(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $phones = $this->category($shop, 'Mobile Phone', 'mobile');
        $this->category($shop, 'Accessory', 'accessory');
        $a15 = $this->item($shop, 'A15', 'mobile');
        $this->item($shop, 'Cable', 'accessory');

        $this->send(new WhatsAppChatbot($connection), 'cat:'.$phones->id);

        $this->assertSame(['product:'.$a15->id], $this->lastListIds());
        $list = collect($this->sentBodies())->where('interactive.type', 'list')->last();
        $this->assertStringStartsWith('*Mobile Phone*', $list['interactive']['body']['text']);
    }

    public function test_one_category_holding_everything_skips_straight_to_its_sub_categories(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $accessories = $this->category($shop, 'Accessory', 'accessory');
        $chargers = $this->subCategory($accessories, 'Charger');
        $this->item($shop, 'Fast charger', 'accessory', 'Charger');

        $this->send(new WhatsAppChatbot($connection), 'browse');

        $this->assertSame(['list:c'.$accessories->id.':1', 'list:s'.$chargers->id.':1'], $this->lastListIds());
    }

    public function test_products_outside_every_category_keep_the_category_menu_with_all_products(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $phones = $this->category($shop, 'Mobile Phone', 'mobile');
        $this->item($shop, 'A15', 'mobile');
        $sim = $this->item($shop, 'Legacy SIM', 'sim');
        $bot = new WhatsAppChatbot($connection);

        $this->send($bot, 'browse');
        $this->assertSame(['browse:page:1', 'cat:'.$phones->id], $this->lastListIds());

        $this->send($bot, 'browse:page:1');
        $this->assertContains('product:'.$sim->id, $this->lastListIds());
    }

    public function test_long_menus_and_lists_paginate_and_keep_their_scope(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $categories = collect(range(1, 10))->map(function ($n) use ($shop) {
            $category = $this->category($shop, sprintf('Cat %02d', $n));
            $this->item($shop, "Item {$n}", $category->slug);

            return $category;
        });
        $big = $categories->first();
        foreach (range(1, 11) as $n) {
            $this->item($shop, sprintf('Extra %02d', $n), $big->slug);
        }
        $bot = new WhatsAppChatbot($connection);

        $this->send($bot, 'browse');
        $ids = $this->lastListIds();
        $this->assertCount(10, $ids);
        $this->assertSame('browse:page:1', $ids[0]);
        $this->assertSame('cats:2', $ids[9]);

        $this->send($bot, 'cats:2');
        $this->assertSame(['browse:page:1', 'cat:'.$categories[8]->id, 'cat:'.$categories[9]->id], $this->lastListIds());

        $this->send($bot, 'cat:'.$big->id);
        $ids = $this->lastListIds();
        $this->assertCount(10, $ids);
        $this->assertSame('list:c'.$big->id.':2', $ids[9]);

        $this->send($bot, 'list:c'.$big->id.':2');
        $this->assertCount(3, $this->lastListIds());
    }

    public function test_catalog_keyword_opens_the_category_menu(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $phones = $this->category($shop, 'Mobile Phone', 'mobile');
        $accessories = $this->category($shop, 'Accessory', 'accessory');
        $this->item($shop, 'A15', 'mobile');
        $this->item($shop, 'Cable', 'accessory');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);

        (new WhatsAppChatbot($connection))->handleInbound($this->textMessage($this->customer, 'catalog'), null);

        $this->assertSame(['browse:page:1', 'cat:'.$accessories->id, 'cat:'.$phones->id], $this->lastListIds());
    }

    // ── Cross-shop isolation for every new query path ──────────────────

    public function test_another_shops_categories_and_sub_categories_never_appear_or_resolve(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        [$otherShop] = $this->whatsappShop('Shop B', '999');
        $mine = $this->category($shop, 'Accessory', 'accessory');
        $this->category($shop, 'Mobile Phone', 'mobile');
        $mySub = $this->subCategory($mine, 'Charger');
        $this->item($shop, 'My charger', 'accessory', 'Charger');
        $this->item($shop, 'My phone', 'mobile');

        // Shop B uses the very same slugs and sub-category name.
        $theirs = $this->category($otherShop, 'Accessory', 'accessory');
        $theirPhones = $this->category($otherShop, 'Gadgets');
        $theirSub = $this->subCategory($theirs, 'Charger');
        $theirOnlySub = $this->subCategory($theirs, 'Secret Sub');
        $this->item($otherShop, 'Their charger', 'accessory', 'Charger');
        $this->item($otherShop, 'Their secret', 'accessory', 'Secret Sub');
        $this->item($otherShop, 'Their gadget', 'gadgets');
        $bot = new WhatsAppChatbot($connection);

        $this->send($bot, 'browse');
        $this->assertNotContains('cat:'.$theirs->id, $this->lastListIds());
        $this->assertNotContains('cat:'.$theirPhones->id, $this->lastListIds());

        $this->send($bot, 'cat:'.$mine->id);
        $this->assertSame(['list:c'.$mine->id.':1', 'list:s'.$mySub->id.':1'], $this->lastListIds());
        $this->assertNotContains('list:s'.$theirOnlySub->id.':1', $this->lastListIds());

        foreach (['cat:'.$theirs->id, 'list:c'.$theirs->id.':1', 'list:s'.$theirSub->id.':1', 'list:o'.$theirs->id.':1', 'list:c'.$theirPhones->id.':1'] as $foreignId) {
            Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
            $bot->handleInbound($this->replyMessage($this->customer, $foreignId, 'list_reply'), null);

            $this->assertSame('Sorry, nothing is available in that section right now.', $this->lastText(), $foreignId);
            $sent = json_encode($this->sentBodies());
            $this->assertStringNotContainsString('Their', $sent, $foreignId);
            $this->assertStringNotContainsString('Secret', $sent, $foreignId);
        }
    }
}
