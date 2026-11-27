<?php

namespace App\Storefronts;

use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Support\StoreCallbackGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A WooCommerce (WordPress) store (O9), through WooCommerce's REST API v3.
 *
 * Keys: authorizeUrl() sends the store owner to WooCommerce's own app
 * authorisation page (/wc-auth/v1/authorize, scope read_write). On approval
 * WooCommerce POSTs JSON {key_id, user_id, consumer_key, consumer_secret,
 * key_permissions} to our https callback_url (user_id is our one-time state),
 * which must answer 200 or the key is deleted, then sends the owner to our
 * return_url. Calls use those keys with HTTP Basic auth, https only.
 *
 * Webhooks are created by API (POST /wc/v3/webhooks) with the connection's
 * own secret. WooCommerce signs each delivery: X-WC-Webhook-Signature is the
 * BASE64 HMAC-SHA256 of the raw body keyed with that secret, and the topic is
 * in X-WC-Webhook-Topic. Creating a webhook sends a "ping" (form body
 * webhook_id=N), which is answered 200 and otherwise ignored.
 *
 * The store's address comes from the shop, so every call goes through the
 * same guard as our own callbacks: https on 443, a public address, connected
 * to the address that was checked, no redirects.
 */
class WooCommerce extends PlatformStore
{
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated'];

    /** WooCommerce's batch endpoints take up to 100 updates. */
    public const STOCK_BATCH = 100;

    /** Orders worth importing: paid, or cash on delivery waiting to ship. Pending ones are still at checkout. */
    public const IMPORTED_STATUSES = ['processing', 'on-hold', 'completed'];

    public const CANCELLED_STATUSES = ['cancelled', 'refunded', 'failed'];

    public function __construct(protected StoreCallbackGuard $guard)
    {
    }

    /** https://shop.pk or https://shop.pk/store, without a trailing slash; null when not usable. */
    public static function storeUrl(string $input): ?string
    {
        $url = rtrim(trim($input), '/');

        if (! str_starts_with($url, 'http')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null) || isset($parts['query']) || isset($parts['user'])) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).rtrim($parts['path'] ?? '', '/');
    }

    public function authorizeUrl(string $storeUrl, string $state): string
    {
        return $storeUrl.'/wc-auth/v1/authorize?'.http_build_query([
            'app_name' => config('app.name'),
            'scope' => 'read_write',
            'user_id' => $state,
            'return_url' => route('storefronts.woocommerce.return', ['state' => $state]),
            'callback_url' => route('storefronts.woocommerce.keys'),
        ]);
    }

    public function verifyWebhook(string $rawBody, ?string $header, string $secret): bool
    {
        return filled($header) && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), (string) $header);
    }

    /** @param  array<string, mixed>  $query */
    protected function call(StoreConnection $connection, string $method, string $path, array $data = []): Response
    {
        $base = (string) $connection->external_domain;
        $host = (string) parse_url($base, PHP_URL_HOST);

        if ($this->guard->problemWith($base) !== null || ($address = $this->guard->safeAddressFor($host)) === null) {
            throw new StorefrontException('The store\'s address isn\'t reachable from the internet.');
        }

        $pin = $host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address);

        try {
            $request = Http::withoutRedirecting()->connectTimeout(5)->timeout(20)->acceptJson()
                ->withBasicAuth((string) $connection->credential('consumer_key'), (string) $connection->credential('consumer_secret'))
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
            $url = $base.'/wp-json/wc/v3/'.ltrim($path, '/');
            $response = $method === 'get' ? $request->get($url, $data) : $request->{$method}($url, $data);
        } catch (ConnectionException) {
            throw new StorefrontException('Couldn\'t reach the store.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new StorefrontException('The store refused our keys. Connect it again from Settings.');
        }

        if (! $response->successful()) {
            throw new StorefrontException('The store answered with status '.$response->status().'.');
        }

        return $response;
    }

    public function catalog(StoreConnection $connection): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->call($connection, 'get', 'products', ['per_page' => 100, 'page' => $page, 'status' => 'publish']);

            foreach ((array) $response->json() as $product) {
                if (($product['type'] ?? '') === 'variable') {
                    foreach ((array) $this->call($connection, 'get', 'products/'.$product['id'].'/variations', ['per_page' => 100])->json() as $variation) {
                        $items[] = ['external_ref' => 'p'.$product['id'].':v'.$variation['id'], 'inventory_ref' => null, 'sku' => filled($variation['sku'] ?? null) ? trim($variation['sku']) : null,
                            'name' => trim(($product['name'] ?? '').' '.collect($variation['attributes'] ?? [])->pluck('option')->implode(' / '))];
                    }

                    continue;
                }

                $items[] = ['external_ref' => 'p'.$product['id'], 'inventory_ref' => null, 'sku' => filled($product['sku'] ?? null) ? trim($product['sku']) : null, 'name' => (string) ($product['name'] ?? '')];
            }

            $pages = (int) ($response->header('X-WP-TotalPages') ?: 1);
            $page++;
        } while ($page <= $pages && $page <= 100);

        return $items;
    }

    public function setStock(StoreConnection $connection, array $items): void
    {
        $updates = collect($items)->map(function (array $item) {
            preg_match('/^p(\d+)(?::v(\d+))?$/', (string) $item['row']->external_ref, $m);

            return ['parent' => isset($m[2]) ? (int) $m[1] : null, 'id' => (int) ($m[2] ?? $m[1] ?? 0), 'stock_quantity' => $item['available'], 'manage_stock' => true];
        })->filter(fn (array $update) => $update['id'] > 0);

        foreach ($updates->whereNull('parent')->chunk(self::STOCK_BATCH) as $batch) {
            $this->call($connection, 'post', 'products/batch', ['update' => $batch->map(fn ($u) => collect($u)->except('parent')->all())->values()->all()]);
        }

        foreach ($updates->whereNotNull('parent')->groupBy('parent') as $parent => $variations) {
            foreach ($variations->chunk(self::STOCK_BATCH) as $batch) {
                $this->call($connection, 'post', 'products/'.$parent.'/variations/batch', ['update' => $batch->map(fn ($u) => collect($u)->except('parent')->all())->values()->all()]);
            }
        }
    }

    public function registerWebhooks(StoreConnection $connection): void
    {
        $ids = [];

        foreach (self::WEBHOOK_TOPICS as $topic) {
            $ids[$topic] = $this->call($connection, 'post', 'webhooks', [
                'name' => config('app.name').' '.$topic,
                'topic' => $topic,
                'delivery_url' => route('storefronts.woocommerce.webhook', $connection->public_id),
                'secret' => $connection->signing_secret,
                'status' => 'active',
            ])->json('id');
        }

        $connection->update(['settings' => array_merge($connection->settings ?? [], ['webhooks' => $ids])]);
    }

    /**
     * A WooCommerce order in the contract's order shape (StoreOrders::rules), or
     * null while it's still at checkout. Cash on delivery: unpaid. Paid online
     * (date_paid set): prepaid for the total. A bank transfer not yet in: unpaid.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    public static function order(array $order): ?array
    {
        if (! in_array($order['status'] ?? '', self::IMPORTED_STATUSES, true)) {
            return null;
        }

        $shipping = (array) ($order['shipping'] ?? []);
        $billing = (array) ($order['billing'] ?? []);
        $address = filled($shipping['address_1'] ?? null) ? $shipping : $billing;
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));
        $phone = filled($shipping['phone'] ?? null) ? $shipping['phone'] : ($billing['phone'] ?? null);
        $cod = ($order['payment_method'] ?? '') === 'cod';
        $paid = ! $cod && filled($order['date_paid'] ?? null);

        return [
            'external_order_id' => (string) ($order['id'] ?? ''),
            'placed_at' => (string) ($order['date_created_gmt'] ?? $order['date_created'] ?? now()->toIso8601String()).(isset($order['date_created_gmt']) && ! str_ends_with((string) $order['date_created_gmt'], 'Z') ? 'Z' : ''),
            'customer' => [
                'name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                'phone' => filled($phone) ? mb_substr(trim((string) $phone), 0, 50) : null,
                'address' => mb_substr(trim(implode(', ', array_filter([$address['address_1'] ?? null, $address['address_2'] ?? null]))), 0, 500) ?: null,
                'area' => mb_substr(trim(implode(', ', array_filter([$address['city'] ?? null, $address['state'] ?? null]))), 0, 200) ?: null,
            ],
            'payment' => [
                'method' => $cod && filled($phone) ? StoreOrder::COD : StoreOrder::PREPAID,
                'amount_paid' => $paid ? round((float) ($order['total'] ?? 0), 2) : 0,
            ],
            'shipping_amount' => round((float) ($order['shipping_total'] ?? 0), 2),
            'discount_amount' => round((float) ($order['discount_total'] ?? 0), 2),
            'note' => mb_substr(trim('WooCommerce order #'.($order['number'] ?? $order['id'] ?? '').(filled($order['customer_note'] ?? null) ? ': '.$order['customer_note'] : '')), 0, 500),
            'items' => collect($order['line_items'] ?? [])->map(fn (array $line) => [
                'external_sku' => self::skuFor($line),
                'name' => mb_substr((string) ($line['name'] ?? 'Item'), 0, 255),
                'quantity' => (int) ($line['quantity'] ?? 0),
                // Price before any coupon, per piece; coupons come off as discount_amount.
                'unit_price' => round((float) ($line['subtotal'] ?? 0) / max(1, (int) ($line['quantity'] ?? 1)), 2),
            ])->values()->all(),
        ];
    }

    public static function skuFor(array $line): string
    {
        if (filled($line['sku'] ?? null)) {
            return trim((string) $line['sku']);
        }

        return ! empty($line['variation_id']) ? 'woo-p'.($line['product_id'] ?? 0).':v'.$line['variation_id'] : 'woo-p'.($line['product_id'] ?? 0);
    }

    /** The same key for a catalogue item: its SKU, else its ids. */
    public static function catalogSku(array $item): string
    {
        return $item['sku'] ?? 'woo-'.$item['external_ref'];
    }
}
