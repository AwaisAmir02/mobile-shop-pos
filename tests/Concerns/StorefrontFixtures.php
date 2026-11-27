<?php

namespace Tests\Concerns;

use App\Support\StoreCallbackGuard;

/**
 * Shopify and WooCommerce as the tests see them (O9): our Shopify app's keys,
 * signatures made the way each platform makes them, and no real DNS.
 */
trait StorefrontFixtures
{
    protected string $shopifySecret = 'shpss_test_secret';

    protected function useShopifyApp(): void
    {
        config(['services.shopify.client_id' => 'shopify-client-id', 'services.shopify.client_secret' => $this->shopifySecret, 'services.shopify.api_version' => '2026-10']);
    }

    /** Every name resolves to a public address unless a test says otherwise. */
    protected function publicDns(): void
    {
        $this->app->instance(StoreCallbackGuard::class, new class extends StoreCallbackGuard
        {
            public array $answers = [];

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? ['93.184.216.34'];
            }
        });
    }

    /** Shopify's OAuth callback query, signed as Shopify signs it (hex HMAC of the sorted other parameters). */
    protected function shopifyCallback(array $params): array
    {
        ksort($params);
        $message = collect($params)->map(fn ($value, $key) => $key.'='.$value)->implode('&');

        return $params + ['hmac' => hash_hmac('sha256', $message, $this->shopifySecret)];
    }

    /** A Shopify webhook delivery, signed as Shopify signs it (base64 HMAC of the raw body). */
    protected function postShopify(string $topic, string $domain, array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload);

        return $this->call('POST', route('storefronts.shopify.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-'.md5($raw),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, $secret ?? $this->shopifySecret, true)),
        ], $raw);
    }

    /** A WooCommerce webhook delivery, signed with the connection's webhook secret. */
    protected function postWoo(string $publicId, string $topic, array $payload, string $secret)
    {
        $raw = json_encode($payload);

        return $this->call('POST', route('storefronts.woocommerce.webhook', $publicId), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => (string) random_int(1, 999999),
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $raw, $secret, true)),
        ], $raw);
    }

    /** A Shopify orders/create payload with one line. */
    protected function shopifyOrder(int $id, string $sku, int $quantity = 1, string $financial = 'pending', float $price = 3500): array
    {
        return [
            'id' => $id, 'name' => '#'.$id, 'created_at' => '2026-11-20T12:00:00+05:00', 'financial_status' => $financial,
            'total_price' => (string) ($price * $quantity + 200), 'total_discounts' => '0.00', 'note' => null,
            'shipping_lines' => [['price' => '200.00']],
            'shipping_address' => ['name' => 'Sana Ali', 'address1' => 'House 7', 'address2' => 'Block B', 'city' => 'Karachi', 'province' => 'Sindh', 'phone' => '+92 300 1112223'],
            'line_items' => [['sku' => $sku, 'name' => 'Lawn Suit', 'title' => 'Lawn Suit', 'quantity' => $quantity, 'price' => (string) $price, 'variant_id' => 555]],
        ];
    }

    /** A WooCommerce order payload with one line. */
    protected function wooOrder(int $id, string $sku, string $status = 'processing', string $method = 'cod', float $price = 3500): array
    {
        return [
            'id' => $id, 'number' => (string) $id, 'status' => $status, 'payment_method' => $method, 'date_paid' => $method === 'cod' ? null : '2026-11-20T07:00:00',
            'date_created_gmt' => '2026-11-20T07:00:00', 'total' => (string) ($price + 250), 'shipping_total' => '250.00', 'discount_total' => '0.00',
            'billing' => ['first_name' => 'Hira', 'last_name' => 'Khan', 'phone' => '03334445556', 'address_1' => 'Flat 3', 'city' => 'Lahore'],
            'shipping' => ['first_name' => 'Hira', 'last_name' => 'Khan', 'address_1' => 'Flat 3', 'city' => 'Lahore'],
            'line_items' => [['sku' => $sku, 'name' => 'Lawn Suit', 'quantity' => 1, 'subtotal' => (string) $price, 'total' => (string) $price, 'product_id' => 81, 'variation_id' => 0]],
        ];
    }
}
