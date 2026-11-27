<?php

namespace App\Http\Controllers\Store;

use App\Actions\StoreConnections;
use App\Actions\StorefrontConnections;
use App\Actions\StoreOrders;
use App\Enums\StoreDriver;
use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Storefronts\Shopify;
use App\Storefronts\WooCommerce;
use App\Support\ApiShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Webhooks from Shopify and WooCommerce (O9). Stateless, no session or CSRF.
 * Each is checked against its platform's signature before anything is read.
 *
 * An order that maps to an invalid one (no items, say) is answered 200 and noted on
 * the connection: telling the platform to retry would only resend the same order.
 */
class StorefrontWebhookController extends Controller
{
    public function shopify(Request $request, Shopify $shopify): Response|JsonResponse
    {
        $raw = $request->getContent();

        if (! $shopify->verifyWebhook($raw, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid signature.', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $domain = strtolower((string) $request->header('X-Shopify-Shop-Domain'));
        $payload = json_decode($raw, true) ?: [];
        $connections = StoreConnection::withoutGlobalScopes()->where('driver', StoreDriver::Shopify->value)->where('external_domain', $domain)->get();

        // Privacy topics, required of every Shopify app.
        if ($topic === 'shop/redact') {
            $connections->each(fn (StoreConnection $connection) => StorefrontConnections::disconnected($connection));

            return response('OK');
        }

        if ($topic === 'customers/redact') {
            $ids = array_map('strval', (array) ($payload['orders_to_redact'] ?? []));
            StoreOrder::withoutGlobalScopes()->whereIn('store_connection_id', $connections->pluck('id'))->whereIn('external_order_id', $ids)
                ->update(['customer_name' => null, 'customer_phone' => null, 'delivery_address' => null, 'delivery_area' => null]);

            return response('OK');
        }

        if ($topic === 'customers/data_request') {
            // What we hold is on each order's page in the shop's online-orders inbox; the shop answers the customer.
            return response('OK');
        }

        $connection = $connections->whereNull('removed_at')->first();

        if ($connection === null) {
            return response('OK');
        }

        return ApiShopContext::current()->run($connection->shop_id, function () use ($connection, $topic, $payload, $request) {
            match ($topic) {
                'app/uninstalled' => StorefrontConnections::disconnected($connection),
                'orders/create' => $this->receive($connection, fn () => Shopify::order($payload), 'shopify:'.$request->header('X-Shopify-Webhook-Id')),
                'orders/cancelled' => $this->cancel($connection, (string) ($payload['id'] ?? ''), $payload['cancel_reason'] ?? null),
                default => null,
            };

            return response('OK');
        });
    }

    public function wooCommerce(Request $request, WooCommerce $woo, string $connection): Response
    {
        $connection = StoreConnection::withoutGlobalScopes()->where('public_id', $connection)->where('driver', StoreDriver::WooCommerce->value)->whereNull('removed_at')->firstOrFail();
        $raw = $request->getContent();

        // Sent once when a webhook is created, before any secret is in play.
        if (! $request->hasHeader('X-WC-Webhook-Signature') && str_starts_with($raw, 'webhook_id=')) {
            return response('OK');
        }

        if (! $woo->verifyWebhook($raw, $request->header('X-WC-Webhook-Signature'), (string) $connection->signing_secret)) {
            return response('Invalid signature.', 401);
        }

        $payload = json_decode($raw, true) ?: [];

        return ApiShopContext::current()->run($connection->shop_id, function () use ($connection, $payload, $request) {
            if (in_array($payload['status'] ?? '', WooCommerce::CANCELLED_STATUSES, true)) {
                $this->cancel($connection, (string) ($payload['id'] ?? ''), 'Marked '.$payload['status'].' on WooCommerce');
            } else {
                $this->receive($connection, fn () => WooCommerce::order($payload), 'woo:'.$request->header('X-WC-Webhook-Delivery-ID', (string) ($payload['id'] ?? '')));
            }

            return response('OK');
        });
    }

    /** WooCommerce's key handover (callback_url): answered straight away. */
    public function wooCommerceKeys(Request $request): Response
    {
        $connection = StorefrontConnections::receiveWooKeys($request->json()->all() ?: $request->all());

        return $connection !== null ? response('OK') : response('Unknown request.', 400);
    }

    protected function receive(StoreConnection $connection, \Closure $map, string $eventId): void
    {
        $data = $map();

        if ($data === null) {
            return;
        }

        try {
            StoreOrders::receiveMapped($connection, $data, $eventId);
        } catch (ValidationException $e) {
            $message = 'An order couldn\'t be read: '.collect($e->errors())->flatten()->first();
            $connection->update(['last_delivery_error' => mb_substr($message, 0, 255)]);
            Log::warning('Store platform order not imported', ['connection' => $connection->id, 'errors' => $e->errors()]);
        }
    }

    protected function cancel(StoreConnection $connection, string $externalOrderId, ?string $reason): void
    {
        if (($order = StoreOrders::find($connection, $externalOrderId)) !== null) {
            StoreOrders::cancelFromStore($order, $reason !== null ? mb_substr((string) $reason, 0, 255) : null);
        }
    }
}
