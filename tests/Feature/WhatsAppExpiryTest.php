<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RestockSubscription;
use App\Models\StockIn;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrderItem;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppExpiryTest extends TestCase
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

    protected function batch(Product $product, int $quantity, ?string $expiry): void
    {
        StockIn::withoutGlobalScopes()->create([
            'shop_id' => $product->shop_id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'quantity_remaining' => $quantity, 'selling_price' => 100,
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'stock_date' => now()->toDateString(), 'expiry_date' => $expiry,
        ]);
    }

    /** @return array<int, string> */
    protected function catalogueIds(WhatsAppChatbot $bot): array
    {
        $this->reply($bot, 'browse:page:1');

        $list = collect($this->sentBodies())->where('interactive.type', 'list')->last();

        return array_column($list['interactive']['action']['sections'][0]['rows'], 'id');
    }

    public function test_a_product_is_hidden_only_once_all_its_stock_is_expired(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $shop->update(['expiry_tracking_enabled' => true]);
        $allExpired = $this->listedProduct($shop, 'All Expired', stock: 2);
        $this->batch($allExpired, 2, now()->subDays(3)->toDateString());
        $partly = $this->listedProduct($shop, 'Partly Stocked', stock: 5);
        $this->batch($partly, 2, now()->subDays(3)->toDateString());
        $this->batch($partly, 3, now()->addDays(3)->toDateString());
        $nearly = $this->listedProduct($shop, 'Nearly Expired', stock: 2);
        $this->batch($nearly, 2, now()->addDay()->toDateString());
        $legacy = $this->listedProduct($shop, 'With Uncovered Stock', stock: 5);
        $this->batch($legacy, 2, now()->subDays(3)->toDateString());

        $bot = new WhatsAppChatbot($connection);

        $this->assertEqualsCanonicalizing(
            ['product:'.$partly->id, 'product:'.$nearly->id, 'product:'.$legacy->id],
            $this->catalogueIds($bot),
        );

        $this->reply($bot, 'product:'.$allExpired->id);
        $this->assertStringContainsString(__('whatsapp.product_unavailable', [], 'en'), json_encode(collect($this->sentBodies())->slice(-2)->values(), JSON_UNESCAPED_UNICODE));
    }

    public function test_customers_see_no_near_expiry_flag_and_cannot_order_expired_units(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $shop->update(['expiry_tracking_enabled' => true]);
        $product = $this->listedProduct($shop, 'Partly Stocked', stock: 5);
        $this->batch($product, 2, now()->subDays(3)->toDateString());
        $this->batch($product, 3, now()->addDays(3)->toDateString());
        $bot = new WhatsAppChatbot($connection);

        $this->reply($bot, 'product:'.$product->id);
        $this->assertStringNotContainsStringIgnoringCase('expir', json_encode($this->sentBodies()));

        $this->type($bot, '4');
        $this->assertSame(0, WhatsAppOrderItem::withoutGlobalScopes()->count());

        $this->type($bot, '3');
        $this->assertSame(3, WhatsAppOrderItem::withoutGlobalScopes()->first()->quantity);
    }

    public function test_with_tracking_off_dates_change_nothing_in_the_bot(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Dated', stock: 2);
        $this->batch($product, 2, now()->subDays(3)->toDateString());

        $this->assertSame(['product:'.$product->id], $this->catalogueIds(new WhatsAppChatbot($connection)));
    }

    public function test_restock_alerts_skip_a_product_whose_stock_is_all_expired(): void
    {
        [$shop, , $connection] = $this->whatsappShop();
        $this->approveTemplates($connection);
        $shop->update(['expiry_tracking_enabled' => true]);
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => $this->customer]);

        $expired = $this->listedProduct($shop, 'All Expired', stock: 2);
        $this->batch($expired, 2, now()->subDays(3)->toDateString());
        $fresh = $this->listedProduct($shop, 'Fresh', stock: 2);
        $this->batch($fresh, 2, now()->addDays(3)->toDateString());

        foreach ([$expired, $fresh] as $product) {
            RestockSubscription::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'whatsapp_contact_id' => $contact->id]);
        }

        $this->fakeGraph();
        Artisan::call('whatsapp:follow-ups');

        $this->assertNull(RestockSubscription::withoutGlobalScopes()->where('product_id', $expired->id)->first()->notified_at);
        $this->assertNotNull(RestockSubscription::withoutGlobalScopes()->where('product_id', $fresh->id)->first()->notified_at);
    }
}
