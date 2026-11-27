<?php

namespace App\Storefronts;

use App\Models\StoreConnection;
use App\Models\StoreOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Our Shopify app (O9), one app for every shop, installed by each merchant with
 * Shopify's authorization code grant:
 *
 *  1. installUrl(): the merchant approves our scopes on their store's
 *     /admin/oauth/authorize page.
 *  2. Shopify redirects back with code, shop, state, timestamp and hmac.
 *     verifyCallback() checks the hmac: hex HMAC-SHA256, keyed with the app's
 *     client secret, over every other query parameter sorted by name and joined
 *     as key=value with "&".
 *  3. exchangeCode() POSTs to the store's /admin/oauth/access_token for an
 *     offline (non-expiring) access token, kept encrypted on the connection.
 *
 * Calls go to the Admin GraphQL API (/admin/api/{version}/graphql.json with the
 * X-Shopify-Access-Token header): REST is legacy for new public apps.
 *
 * Webhooks (orders/create, orders/cancelled, app/uninstalled) are subscribed with
 * webhookSubscriptionCreate. Shopify signs each one: X-Shopify-Hmac-Sha256 is the
 * BASE64 HMAC-SHA256 of the raw body keyed with the client secret. The privacy
 * topics (customers/data_request, customers/redact, shop/redact) are declared in
 * the app's configuration, not by API, and arrive at the same address.
 */
class Shopify extends PlatformStore
{
    public const SCOPES = 'read_products,read_inventory,write_inventory,read_orders,read_locations';

    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_CANCELLED', 'APP_UNINSTALLED'];

    /** Shopify allows up to 250 quantities in one inventorySetQuantities call. */
    public const STOCK_BATCH = 250;

    public static function configured(): bool
    {
        return filled(config('services.shopify.client_id')) && filled(config('services.shopify.client_secret'));
    }

    /** A store's own address: shopname.myshopify.com, lowercase, nothing else. */
    public static function shopDomain(string $input): ?string
    {
        $domain = strtolower(trim($input));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) $domain, '/');

        if (! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $domain) ? $domain : null;
    }

    public function installUrl(string $shopDomain, string $state): string
    {
        return 'https://'.$shopDomain.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => self::SCOPES,
            'redirect_uri' => route('storefronts.shopify.callback'),
            'state' => $state,
        ]);
    }

    /** @param  array<string, mixed>  $query  the callback's query string, as received */
    public function verifyCallback(array $query): bool
    {
        $given = (string) ($query['hmac'] ?? '');

        if ($given === '' || self::shopDomain((string) ($query['shop'] ?? '')) === null) {
            return false;
        }

        $message = collect($query)->except(['hmac', 'signature'])
            ->map(fn ($value) => is_array($value) ? implode(',', $value) : (string) $value)
            ->sortKeys(SORT_STRING)
            ->map(fn (string $value, string $key) => $key.'='.$value)
            ->implode('&');

        return hash_equals(hash_hmac('sha256', $message, (string) config('services.shopify.client_secret')), strtolower($given));
    }

    public function verifyWebhook(string $rawBody, ?string $header): bool
    {
        if (blank($header) || ! self::configured()) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, (string) config('services.shopify.client_secret'), true)), (string) $header);
    }

    /** @return array{access_token: string, scope: string} */
    public function exchangeCode(string $shopDomain, string $code): array
    {
        try {
            $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(15)->acceptJson()
                ->post('https://'.$shopDomain.'/admin/oauth/access_token', [
                    'client_id' => config('services.shopify.client_id'),
                    'client_secret' => config('services.shopify.client_secret'),
                    'code' => $code,
                ]);
        } catch (ConnectionException) {
            throw new StorefrontException('Couldn\'t reach Shopify to finish connecting. Try again.');
        }

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new StorefrontException('Shopify didn\'t accept the connection (status '.$response->status().'). Start again from Settings.');
        }

        return ['access_token' => (string) $response->json('access_token'), 'scope' => (string) $response->json('scope', '')];
    }

    protected function client(StoreConnection $connection): PendingRequest
    {
        return Http::withoutRedirecting()->connectTimeout(5)->timeout(20)->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => (string) $connection->credential('access_token')]);
    }

    protected function endpoint(StoreConnection $connection): string
    {
        return 'https://'.$connection->external_domain.'/admin/api/'.config('services.shopify.api_version').'/graphql.json';
    }

    /**
     * One GraphQL call. Top-level errors and a mutation's userErrors both throw.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the data
     */
    public function graphql(StoreConnection $connection, string $query, array $variables = [], ?string $mutation = null): array
    {
        try {
            $response = $this->client($connection)->post($this->endpoint($connection), ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException) {
            throw new StorefrontException('Couldn\'t reach Shopify.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new StorefrontException('Shopify refused our access. Reconnect the store from Settings.');
        }

        if (! $response->successful()) {
            throw new StorefrontException('Shopify answered with status '.$response->status().'.');
        }

        if (($errors = $response->json('errors')) !== null) {
            throw new StorefrontException('Shopify: '.collect((array) $errors)->pluck('message')->filter()->implode('; '));
        }

        $data = (array) $response->json('data', []);

        if ($mutation !== null && ($userErrors = $data[$mutation]['userErrors'] ?? []) !== []) {
            throw new StorefrontException('Shopify: '.collect($userErrors)->pluck('message')->implode('; '));
        }

        return $data;
    }

    /** The store's first location, where stock is set. */
    public function primaryLocation(StoreConnection $connection): string
    {
        $data = $this->graphql($connection, 'query { locations(first: 1) { nodes { id name } } }');

        return (string) ($data['locations']['nodes'][0]['id'] ?? throw new StorefrontException('This Shopify store has no location to keep stock at.'));
    }

    public function catalog(StoreConnection $connection): array
    {
        $variants = [];
        $after = null;

        do {
            $data = $this->graphql($connection, 'query($after: String) { productVariants(first: 100, after: $after) { nodes { id sku displayName inventoryItem { id tracked } } pageInfo { hasNextPage endCursor } } }', ['after' => $after]);
            $page = $data['productVariants'] ?? [];

            foreach ($page['nodes'] ?? [] as $node) {
                $variants[] = [
                    'external_ref' => (string) $node['id'],
                    'inventory_ref' => $node['inventoryItem']['id'] ?? null,
                    'sku' => filled($node['sku'] ?? null) ? trim((string) $node['sku']) : null,
                    'name' => (string) ($node['displayName'] ?? ''),
                ];
            }

            $after = ($page['pageInfo']['hasNextPage'] ?? false) ? $page['pageInfo']['endCursor'] : null;
        } while ($after !== null && count($variants) < 10000);

        return $variants;
    }

    public function setStock(StoreConnection $connection, array $items): void
    {
        $location = (string) $connection->setting('location_id');

        if ($location === '') {
            throw new StorefrontException('No Shopify location is set for this store. Import the catalogue again.');
        }

        $quantities = collect($items)->filter(fn (array $item) => filled($item['row']->inventory_ref))
            ->map(fn (array $item) => [
                'inventoryItemId' => $item['row']->inventory_ref,
                'locationId' => $location,
                'quantity' => $item['available'],
                // Since 2026-04 each quantity names what it changes from; null sets it outright,
                // which is right here: this app is the only truth for stock.
                'changeFromQuantity' => null,
            ])->values();

        foreach ($quantities->chunk(self::STOCK_BATCH) as $batch) {
            $this->graphql($connection, 'mutation($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { inventoryAdjustmentGroup { id } userErrors { field message code } } }', [
                'input' => ['name' => 'available', 'reason' => 'correction', 'referenceDocumentUri' => 'gid://hisabokitab/StoreConnection/'.$connection->id, 'quantities' => $batch->values()->all()],
            ], 'inventorySetQuantities');
        }
    }

    public function registerWebhooks(StoreConnection $connection): void
    {
        $ids = [];

        foreach (self::WEBHOOK_TOPICS as $topic) {
            $data = $this->graphql($connection, 'mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } } }', [
                'topic' => $topic,
                'sub' => ['uri' => route('storefronts.shopify.webhook'), 'format' => 'JSON'],
            ], 'webhookSubscriptionCreate');

            $ids[$topic] = $data['webhookSubscriptionCreate']['webhookSubscription']['id'] ?? null;
        }

        $connection->update(['settings' => array_merge($connection->settings ?? [], ['webhooks' => $ids])]);
    }

    /**
     * An orders/create payload in the contract's order shape (StoreOrders::rules).
     * Paid at checkout: prepaid for the total. Anything else (cash on delivery,
     * a bank transfer still to come): unpaid.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function order(array $order): array
    {
        $address = (array) ($order['shipping_address'] ?? $order['billing_address'] ?? []);
        $customer = (array) ($order['customer'] ?? []);
        $name = trim((string) ($address['name'] ?? trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? ''))));
        $paid = ($order['financial_status'] ?? null) === 'paid';
        $shipping = collect($order['shipping_lines'] ?? [])->sum(fn ($line) => (float) ($line['discounted_price'] ?? $line['price'] ?? 0));
        $phone = self::firstFilled([$address['phone'] ?? null, $order['phone'] ?? null, $customer['phone'] ?? null]);

        return [
            'external_order_id' => (string) ($order['id'] ?? ''),
            'placed_at' => (string) ($order['created_at'] ?? now()->toIso8601String()),
            'customer' => [
                'name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                'phone' => $phone,
                'address' => mb_substr(trim(implode(', ', array_filter([$address['address1'] ?? null, $address['address2'] ?? null]))), 0, 500) ?: null,
                'area' => mb_substr(trim(implode(', ', array_filter([$address['city'] ?? null, $address['province'] ?? null]))), 0, 200) ?: null,
            ],
            'payment' => [
                // Unpaid with no phone can't be cash on delivery in the contract; it arrives unpaid all the same.
                'method' => $paid || $phone === null ? StoreOrder::PREPAID : StoreOrder::COD,
                'amount_paid' => $paid ? round((float) ($order['total_price'] ?? 0), 2) : 0,
            ],
            'shipping_amount' => round((float) $shipping, 2),
            'discount_amount' => round((float) ($order['total_discounts'] ?? 0), 2),
            'note' => mb_substr(trim('Shopify order '.($order['name'] ?? '').(filled($order['note'] ?? null) ? ': '.$order['note'] : '')), 0, 500),
            'items' => collect($order['line_items'] ?? [])->map(fn (array $line) => [
                'external_sku' => self::skuFor($line),
                'name' => mb_substr((string) ($line['name'] ?? $line['title'] ?? 'Item'), 0, 255),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => round((float) ($line['price'] ?? 0), 2),
            ])->values()->all(),
        ];
    }

    /** The SKU, or for a variant without one the key StorefrontConnections mapped it under. */
    public static function skuFor(array $line): string
    {
        return filled($line['sku'] ?? null) ? trim((string) $line['sku']) : 'shopify-variant-'.($line['variant_id'] ?? '0');
    }

    /** The same key for a catalogue variant: its SKU, else its numeric id. */
    public static function catalogSku(array $variant): string
    {
        return $variant['sku'] ?? 'shopify-variant-'.preg_replace('/\D+/', '', $variant['external_ref']);
    }

    protected static function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return mb_substr(trim((string) $value), 0, 50);
            }
        }

        return null;
    }
}
