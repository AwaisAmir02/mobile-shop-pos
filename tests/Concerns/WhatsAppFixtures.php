<?php

namespace Tests\Concerns;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Models\PlatformIntegration;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateLibrary;
use Illuminate\Support\Facades\Http;

trait WhatsAppFixtures
{
    protected string $appSecret = 'test-app-secret';

    protected function whatsappIntegration(): PlatformIntegration
    {
        return PlatformIntegration::create([
            'provider' => IntegrationProvider::WhatsApp,
            'app_id' => '1234567890',
            'app_secret' => $this->appSecret,
            'webhook_verify_token' => 'verify-me',
            'config' => ['embedded_signup_config_id' => '555'],
            'enabled' => true,
        ]);
    }

    protected function whatsappShop(string $name = 'Shop A', string $phoneNumberId = '100200300'): array
    {
        $shop = Shop::create(['name' => $name, 'whatsapp_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        $connection = ShopChannelConnection::create([
            'shop_id' => $shop->id,
            'provider' => ChannelProvider::WhatsApp,
            'external_id' => $phoneNumberId,
            'waba_id' => '900',
            'display_name' => $name.' WA',
            'access_token' => 'shop-token-'.$phoneNumberId,
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]);

        return [$shop, $owner, $connection];
    }

    protected function listedProduct(Shop $shop, string $name = 'Charger', float $price = 1500, int $stock = 5, bool $listed = true): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'details' => ['category' => 'Charger'],
            'whatsapp_listed' => $listed,
        ]);
    }

    protected function approveTemplates(ShopChannelConnection $connection, array $languages = ['en', 'ur']): void
    {
        foreach (TemplateLibrary::definitions() as $key => $definition) {
            foreach ($languages as $language) {
                WhatsAppTemplate::create([
                    'shop_id' => $connection->shop_id,
                    'shop_channel_connection_id' => $connection->id,
                    'key' => $key,
                    'language' => $language,
                    'name' => TemplateLibrary::name($key),
                    'category' => $definition['category'],
                    'status' => 'approved',
                ]);
            }
        }
    }

    protected function metaConnections(Shop $shop, bool $instagram = true): array
    {
        $facebook = ShopChannelConnection::create([
            'shop_id' => $shop->id,
            'provider' => ChannelProvider::Facebook,
            'external_id' => 'page-'.$shop->id,
            'display_name' => 'Shop Page',
            'access_token' => 'page-token-'.$shop->id,
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]);

        $ig = $instagram ? ShopChannelConnection::create([
            'shop_id' => $shop->id,
            'provider' => ChannelProvider::Instagram,
            'external_id' => 'ig-'.$shop->id,
            'display_name' => '@shop',
            'access_token' => 'page-token-'.$shop->id,
            'meta' => ['page_id' => 'page-'.$shop->id],
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]) : null;

        return [$facebook, $ig];
    }

    protected function fakeGraph(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-1'], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ]);
    }

    protected function textMessage(string $from, string $body, ?string $id = null): array
    {
        return ['from' => $from, 'id' => $id ?? 'wamid.'.uniqid(), 'type' => 'text', 'text' => ['body' => $body]];
    }

    protected function replyMessage(string $from, string $replyId, string $kind = 'button_reply'): array
    {
        return ['from' => $from, 'id' => 'wamid.'.uniqid(), 'type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $replyId, 'title' => 'x']]];
    }

    protected function webhookBody(string $phoneNumberId, array $messages, array $contacts = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '923000000000', 'phone_number_id' => $phoneNumberId],
                        'contacts' => $contacts,
                        'messages' => $messages,
                    ],
                ]],
            ]],
        ];
    }

    protected function postWebhook(array $body, ?string $secret = null)
    {
        $json = json_encode($body);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, $secret ?? $this->appSecret),
        ], $json);
    }

    protected function sentBodies(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_ends_with($request->url(), '/messages'))
            ->map(fn ($request) => $request->data())
            ->values()
            ->all();
    }
}
