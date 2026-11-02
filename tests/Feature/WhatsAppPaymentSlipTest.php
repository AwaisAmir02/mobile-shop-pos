<?php

namespace Tests\Feature;

use App\Enums\WhatsAppOrderStatus;
use App\Enums\WhatsAppPaymentChoice;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\ShopChannelConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\WhatsAppChatbot;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppPaymentSlipTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Graph fake: messages succeed, media ids resolve to a Meta-hosted file of the given type and size. */
    protected function fakeMedia(array $media = []): void
    {
        $media += [
            'img-1' => ['image/jpeg', 'JPEG-BYTES-ONE'],
            'img-2' => ['image/png', 'PNG-BYTES-TWO'],
            'pdf-1' => ['application/pdf', '%PDF-1.4 receipt'],
        ];

        Http::fake(function (Request $request) use ($media) {
            foreach ($media as $id => $entry) {
                [$mime, $bytes] = $entry;
                $size = $entry[2] ?? null;

                if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/'.$id)) {
                    return Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid='.$id, 'mime_type' => $mime, 'file_size' => $size ?? strlen($bytes)], 200);
                }

                if (str_contains($request->url(), 'lookaside.fbsbx.com') && str_ends_with($request->url(), 'mid='.$id)) {
                    return Http::response($bytes, 200, ['Content-Type' => $mime]);
                }
            }

            return Http::response(['messages' => [['id' => 'wamid.out']]], 200);
        });
    }

    protected function shopAtPayment(string $phoneNumberId = '100200300', bool $withAccount = true): array
    {
        [$shop, $owner, $connection] = $this->whatsappShop('Shop '.$phoneNumberId, $phoneNumberId);
        if ($withAccount) {
            ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank', 'account_number' => '0123456789']);
        }
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);
        $bot = new WhatsAppChatbot($connection);

        foreach (["qty:{$product->id}:1", 'checkout', 'ship:delivery'] as $id) {
            $this->tap($bot, $id);
        }
        foreach (['Ali Khan', 'House 5, Street 2', 'Gulberg'] as $text) {
            $this->type($bot, $text);
        }
        $this->tap($bot, 'phone:whatsapp');
        $this->tap($bot, 'save:no');

        return [$shop, $owner, $connection, $bot];
    }

    protected function tap(WhatsAppChatbot $bot, string $id): void
    {
        $bot->handleInbound($this->replyMessage($this->customer, $id), 'Ali');
    }

    protected function type(WhatsAppChatbot $bot, string $text): void
    {
        $bot->handleInbound($this->textMessage($this->customer, $text), 'Ali');
    }

    protected function sendImage(WhatsAppChatbot $bot, string $mediaId, string $mime = 'image/jpeg'): void
    {
        $bot->handleInbound(['from' => $this->customer, 'id' => 'wamid.'.uniqid(), 'type' => 'image', 'image' => ['id' => $mediaId, 'mime_type' => $mime, 'sha256' => 'x']], 'Ali');
    }

    protected function sendDocument(WhatsAppChatbot $bot, string $mediaId, string $mime = 'application/pdf'): void
    {
        $bot->handleInbound(['from' => $this->customer, 'id' => 'wamid.'.uniqid(), 'type' => 'document', 'document' => ['id' => $mediaId, 'mime_type' => $mime, 'filename' => 'receipt']], 'Ali');
    }

    protected function texts(): array
    {
        return collect($this->sentBodies())->map(fn ($b) => $b['text']['body'] ?? $b['interactive']['body']['text'] ?? null)->filter()->values()->all();
    }

    protected function lastText(): string
    {
        return collect($this->texts())->last();
    }

    protected function order(Shop $shop): WhatsAppOrder
    {
        return WhatsAppOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    public function test_an_online_order_asks_for_the_slip_and_stores_it_privately_with_the_shops_token(): void
    {
        $this->fakeMedia();
        [$shop, , , $bot] = $this->shopAtPayment();
        $this->tap($bot, 'pay:online');

        $this->assertStringStartsWith('After paying, please send a screenshot', $this->lastText());

        $this->sendImage($bot, 'img-1');

        $order = $this->order($shop);
        $this->assertMatchesRegularExpression('#^whatsapp-slips/'.$shop->id.'/'.$order->id.'-[A-Za-z0-9]{20}\.jpg$#', $order->payment_slip_path);
        Storage::disk('local')->assertExists($order->payment_slip_path);
        $this->assertSame('JPEG-BYTES-ONE', Storage::disk('local')->get($order->payment_slip_path));
        $this->assertFalse(Storage::disk('public')->exists($order->payment_slip_path));
        $this->assertContains('Payment screenshot received. ✅', $this->texts());
        $this->assertStringContainsString('Payment: Online transfer (screenshot received)', $this->lastText());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'lookaside.fbsbx.com') && $r->hasHeader('Authorization', 'Bearer shop-token-100200300'));
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/img-1') && $r->hasHeader('Authorization', 'Bearer shop-token-100200300'));

        $this->tap($bot, 'confirm');
        $this->assertSame(WhatsAppOrderStatus::Pending, $this->order($shop)->status);
        $this->assertNotNull($this->order($shop)->payment_slip_path);
    }

    public function test_send_later_then_a_slip_before_the_decision_is_attached_and_a_newer_one_replaces_it(): void
    {
        $this->fakeMedia();
        [$shop, , , $bot] = $this->shopAtPayment();
        $this->tap($bot, 'pay:online');
        $this->tap($bot, 'slip:later');

        $this->assertContains('No problem — send the payment screenshot here anytime before the shop confirms your order.', $this->texts());
        $this->tap($bot, 'confirm');
        $order = $this->order($shop);
        $this->assertSame(WhatsAppOrderStatus::Pending, $order->status);
        $this->assertNull($order->payment_slip_path);

        $this->sendImage($bot, 'img-1');
        $first = $this->order($shop)->payment_slip_path;
        $this->assertSame("Thank you! Your payment screenshot has been added to order {$order->orderNumber()}. The shop will check it shortly.", $this->lastText());

        $this->sendImage($bot, 'img-2', 'image/png');
        $second = $this->order($shop)->payment_slip_path;
        $this->assertStringEndsWith('.png', $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        // Once staff have decided, a picture is no longer expected.
        WhatsAppOrder::withoutGlobalScopes()->whereKey($order->id)->update(['status' => WhatsAppOrderStatus::Rejected->value]);
        $this->sendImage($bot, 'img-1');
        $this->assertStringStartsWith("Thanks! We weren't expecting a picture", $this->lastText());
        $this->assertSame($second, $this->order($shop)->payment_slip_path);
    }

    public function test_a_pdf_receipt_is_accepted(): void
    {
        $this->fakeMedia();
        [$shop, , , $bot] = $this->shopAtPayment();
        $this->tap($bot, 'pay:online');
        $this->sendDocument($bot, 'pdf-1');

        $this->assertStringEndsWith('.pdf', $this->order($shop)->payment_slip_path);
        $this->assertTrue($this->order($shop)->slipIsPdf());
    }

    public function test_wrong_types_and_oversized_files_are_refused_without_storing(): void
    {
        $this->fakeMedia(['big-1' => ['image/jpeg', 'x', 6 * 1024 * 1024], 'gif-1' => ['image/gif', 'GIF89a']]);
        [$shop, , , $bot] = $this->shopAtPayment();
        $this->tap($bot, 'pay:online');

        $this->sendDocument($bot, 'doc-1', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertSame('Please send the payment screenshot as a photo, or as a PDF.', $this->lastText());

        $this->sendImage($bot, 'gif-1', 'image/gif');
        $this->assertSame('Please send the payment screenshot as a photo, or as a PDF.', $this->lastText());

        $this->sendImage($bot, 'big-1');
        $this->assertSame('That file is too large. Please send a photo or PDF under 5 MB.', $this->lastText());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'mid=big-1'));

        $this->assertNull($this->order($shop)->payment_slip_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pictures_are_not_expected_without_an_online_order_and_never_trigger_the_welcome(): void
    {
        $this->fakeMedia();
        [$shop, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $this->sendImage($bot, 'img-1');
        $this->assertSame("Thanks! We weren't expecting a picture or file right now. Type MENU to see our products, or HELP to talk to the shop.", $this->lastText());
        $this->assertSame(1, count($this->sentBodies()));
        Http::assertNotSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/img-1'));

        // A cash-on-delivery order isn't waiting for a slip either.
        [$cashShop, , , $cashBot] = $this->shopAtPayment('555');
        $this->tap($cashBot, 'pay:cash');
        $this->tap($cashBot, 'confirm');
        $this->sendImage($cashBot, 'img-1');
        $this->assertStringStartsWith("Thanks! We weren't expecting a picture", $this->lastText());
        $this->assertNull($this->order($cashShop)->payment_slip_path);
        $this->assertSame(WhatsAppPaymentChoice::CashOnDelivery, $this->order($cashShop)->payment_choice);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_restarting_checkout_or_clearing_the_cart_removes_the_slip(): void
    {
        $this->fakeMedia();
        [$shop, , , $bot] = $this->shopAtPayment();
        $this->tap($bot, 'pay:online');
        $this->sendImage($bot, 'img-1');
        $path = $this->order($shop)->payment_slip_path;

        $this->tap($bot, 'checkout');
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->order($shop)->payment_slip_path);

        $this->tap($bot, 'ship:delivery');
        foreach (['Ali Khan', 'House 5', 'Gulberg'] as $text) {
            $this->type($bot, $text);
        }
        $this->tap($bot, 'phone:whatsapp');
        $this->tap($bot, 'save:no');
        $this->tap($bot, 'pay:online');
        $this->sendImage($bot, 'img-2', 'image/png');
        $path = $this->order($shop)->payment_slip_path;

        $this->tap($bot, 'clear');
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_shop_token_is_never_sent_to_a_non_meta_host(): void
    {
        Http::fake();
        [, , $connection] = $this->whatsappShop();

        $this->expectException(RuntimeException::class);

        try {
            WhatsAppClient::for($connection)->downloadMedia('https://evil.example.com/steal?x=fbsbx.com');
        } finally {
            Http::assertNothingSent();
        }
    }

    // ── Staff view ────────────────────────────────────────────────────

    protected function pendingOnlineOrderWithSlip(Shop $shop, string $ext = 'jpg', string $bytes = 'JPEG-BYTES'): WhatsAppOrder
    {
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '92300'.random_int(1000000, 9999999), 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'payment_choice' => WhatsAppPaymentChoice::Online]);
        $path = "whatsapp-slips/{$shop->id}/{$order->id}-test.{$ext}";
        Storage::disk('local')->put($path, $bytes);
        $order->update(['payment_slip_path' => $path]);

        return $order;
    }

    public function test_staff_see_the_slip_inline_and_can_download_it(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $order = $this->pendingOnlineOrderWithSlip($shop);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->assertSee('Payment screenshot')
            ->assertSeeHtml('src="'.route('whatsapp-orders.slip', $order).'"')
            ->assertSee('Download');

        $inline = $this->get(route('whatsapp-orders.slip', $order));
        $inline->assertOk();
        $this->assertSame('JPEG-BYTES', $inline->streamedContent());
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));

        $download = $this->get(route('whatsapp-orders.slip', ['order' => $order, 'download' => 1]));
        $download->assertOk();
        $this->assertStringContainsString('attachment; filename=WA-', $download->headers->get('Content-Disposition'));

        $pdf = $this->pendingOnlineOrderWithSlip($shop, 'pdf', '%PDF');
        Livewire::test('whatsapp-orders.show', ['order' => $pdf])->assertSee('The customer sent a PDF receipt.');

        $none = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $order->whatsapp_contact_id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'payment_choice' => WhatsAppPaymentChoice::Online]);
        Livewire::test('whatsapp-orders.show', ['order' => $none])->assertSee('No screenshot yet');
        $this->get(route('whatsapp-orders.slip', $none))->assertNotFound();
    }

    public function test_the_slip_route_is_limited_to_the_shop_and_the_whatsapp_orders_permission(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        [$otherShop, $otherOwner] = $this->whatsappShop('Shop B', '999');
        $order = $this->pendingOnlineOrderWithSlip($shop);

        $this->actingAs($otherOwner)->get(route('whatsapp-orders.slip', $order))->assertNotFound();

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $cashier = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($cashier)->get(route('whatsapp-orders.slip', $order))->assertForbidden();

        $shop->update(['whatsapp_enabled' => false]);
        $this->actingAs($owner->fresh())->get(route('whatsapp-orders.slip', $order))->assertStatus(403);
    }

    // ── Cross-shop ────────────────────────────────────────────────────

    public function test_a_slip_only_ever_attaches_to_this_shops_order_using_this_shops_token(): void
    {
        $this->fakeMedia();
        [$shopB, , , $botB] = $this->shopAtPayment('777');
        $this->tap($botB, 'pay:online');
        $this->tap($botB, 'slip:later');
        $this->tap($botB, 'confirm');
        $orderB = $this->order($shopB);

        // The same customer messages Shop A, which has no online order for them.
        [$shopA, , $connectionA] = $this->whatsappShop('Shop A', '444');
        $botA = new WhatsAppChatbot($connectionA);
        $this->sendImage($botA, 'img-1');

        $this->assertStringStartsWith("Thanks! We weren't expecting a picture", $this->lastText());
        $this->assertNull($orderB->fresh()->payment_slip_path);
        Http::assertNotSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer shop-token-444') && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/img-1'));

        // Sent to Shop B, it lands on B's order with B's token.
        $this->sendImage($botB, 'img-1');
        $this->assertStringStartsWith('whatsapp-slips/'.$shopB->id.'/', $orderB->fresh()->payment_slip_path);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'lookaside.fbsbx.com') && $r->hasHeader('Authorization', 'Bearer shop-token-777'));
        $this->assertSame(0, WhatsAppOrder::withoutGlobalScopes()->where('shop_id', $shopA->id)->count());
    }
}
