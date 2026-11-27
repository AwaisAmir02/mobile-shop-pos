# HisabOKitab Online Store API, version 1

For developers connecting an online store to a shop on HisabOKitab.

> **Status:** built: every endpoint and event below is implemented and covered by tests. On Shopify or WooCommerce? You don't need this API: the shop connects those stores itself (docs/storefronts.md).

## Quick start (about an hour)

1. **Get credentials.** The shop owner opens **Settings › Integrations › Online Store**, types a name under **New connection** (your site's domain is fine) and presses **Connect a store**. The screen shows three things once: the **connection ID** (`chn_…`), the **API token** (`hok_…`) and the **signing secret**. Copy them now; only the owner can generate new ones, and that cancels the old token.
2. **Pick the products.** On the same screen the owner ticks which products your store may sell, and can give each one your own code (`external_sku`).
3. **Check you're in:**

   ```bash
   curl -H "Authorization: Bearer hok_YOUR_TOKEN" https://hisabokitab.com/api/channel/v1/me
   ```

4. **Load products and stock** with `GET /products` and `GET /inventory` (below). Show `available` as your stock.
5. **Send each order** as a signed `order.created` event (below), and `order.cancelled` if the customer cancels.
6. **Optional, recommended: receive our events.** The owner pastes your HTTPS endpoint into **Updates to the store** on the connection. You then get stock changes as they happen instead of polling.

Test against a real connection: an order you send is stored as **pending** and changes no stock until the shop accepts it, so the owner can simply reject test orders.

## How it works

HisabOKitab is the only source of truth for stock. Your store reads products and stock from us and sends us orders. It can't change stock any other way.

1. The shop owner creates a connection in **Settings > Integrations** and chooses which products to publish. They give you an API token and a signing secret, each shown once.
2. Your store reads products and stock using the API token.
3. Your store sends each order to us as a signed event.
4. Shop staff accept or reject each order. Stock leaves only when an order is accepted.
5. We send signed events to your store when stock, products or order statuses change.

Every connection belongs to one shop, and its credentials can reach only that shop's data.

## Conventions

- Base URL: `https://hisabokitab.com/api/channel/v1`
- HTTPS only. Requests and responses are JSON, UTF-8.
- Money is a string with two decimals, in PKR: `"1250.00"`.
- Quantities are whole numbers in v1. Products sold by weight or length, and IMEI-tracked products, aren't published.
- Times are ISO 8601 with an offset: `2026-11-07T14:30:00+05:00`.
- Ignore fields you don't recognise. We may add fields within v1.

### Authentication

Send the API token on every REST request:

```
Authorization: Bearer hok_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

| Scope | Allows |
|---|---|
| `catalog:read` | `GET /products`, `GET /products/{id}` |
| `inventory:read` | `GET /inventory` |
| `orders:read` | `GET /orders/{external_order_id}` |

Any valid token can call `GET /me`. Sending order events uses the signing secret, not the API token.

### Errors

```json
{ "error": { "code": "connection_paused", "message": "This connection is paused." } }
```

| HTTP | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Token missing, wrong or revoked |
| 401 | `invalid_signature` | Signature wrong, or timestamp outside the 5-minute window |
| 403 | `insufficient_scope` | Token lacks the required scope |
| 403 | `connection_paused` | Shop's access is paused. **Stop selling until resumed.** |
| 404 | `not_found` | Doesn't exist, or isn't published on this connection |
| 422 | `validation_failed` | See `error.fields` |
| 429 | `rate_limited` | Wait for the `Retry-After` number of seconds |

### Rate limits

Reading endpoints and the order webhook are limited per connection. Over the limit you get `429` with `Retry-After`; wait that many seconds. Polling `GET /inventory?updated_since=` once a minute is well within limits; receiving our events is better still.

### Pagination

List endpoints take `limit` (default 100, maximum 500) and `cursor`. Pass `meta.next_cursor` back until it is `null`.

## REST endpoints

### `GET /me`

```json
{
  "data": {
    "connection_id": "chn_8f3a2c",
    "shop_name": "Ali Mobiles",
    "status": "active",
    "scopes": ["catalog:read", "inventory:read", "orders:read"],
    "price_sync": false,
    "server_time": "2026-11-07T14:30:00+05:00"
  }
}
```

### `GET /products`

Query: `updated_since`, `cursor`, `limit`. Returns published products only.

```json
{
  "data": [
    {
      "id": 4211,
      "external_sku": "CHG-20W-BLK",
      "name": "Fast Charger 20W - Black",
      "group": { "id": 77, "name": "Fast Charger 20W", "variant_label": "Black" },
      "barcode": "8901234567890",
      "category": "Accessories",
      "image_url": "https://hisabokitab.com/storage/products/4211.jpg",
      "price": null,
      "published": true,
      "updated_at": "2026-11-07T13:02:11+05:00"
    }
  ],
  "meta": { "next_cursor": null }
}
```

- Each size or colour is its own product with its own `id`. `group` is `null` for a product with no variants.
- `external_sku` is your own identifier if the shop mapped one, otherwise `null`.
- `price` is `null` unless the shop turned on price sync for this connection. It's the price of the next batch to sell, so it can change when a batch sells out.
- A product removed from sale appears with `published: false` when you use `updated_since`.

`GET /products/{id}` returns one product in the same shape.

### `GET /inventory`

Query: `updated_since`, `cursor`, `limit`.

```json
{
  "data": [
    { "product_id": 4211, "external_sku": "CHG-20W-BLK", "available": 12, "sequence": 90412 }
  ],
  "meta": { "next_cursor": null }
}
```

`available` is an absolute figure. **Replace your number with it; never add or subtract.** It already leaves out expired stock, stock held for pending orders and any safety buffer the shop set, and is never below zero. Ignore any figure whose `sequence` is lower than the last one you stored for that product.

### `GET /orders/{external_order_id}`

```json
{
  "data": {
    "external_order_id": "1001",
    "status": "accepted",
    "fulfillment_status": "packed",
    "reject_reason": null,
    "cancel_flagged": false,
    "updated_at": "2026-11-07T15:10:00+05:00"
  }
}
```

| `status` | Meaning |
|---|---|
| `pending` | Waiting for shop staff. Stock is held from `available`, not yet removed. |
| `accepted` | Sale created, stock removed. |
| `rejected` | Refused by the shop. See `reject_reason`. Refund the customer if they paid. |
| `cancelled` | You cancelled it before the shop decided. |

## Sending order events

`POST https://hisabokitab.com/webhooks/channels/{connection_id}`

| Header | Value |
|---|---|
| `X-HOK-Event-Id` | Your unique id for this event; reuse it on retries |
| `X-HOK-Timestamp` | Unix seconds, when you signed it |
| `X-HOK-Signature` | `v1=` followed by the signature below |

### Signing

```
signature = hex( HMAC-SHA256( signing_secret, timestamp + "." + raw_request_body ) )
```

Sign the exact bytes you send. Don't re-encode the JSON after signing. In PHP:

```php
$body = json_encode($event);
$timestamp = time();
$signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $signingSecret);

$response = (new GuzzleHttp\Client)->post("https://hisabokitab.com/webhooks/channels/{$connectionId}", [
    'headers' => [
        'Content-Type' => 'application/json',
        'X-HOK-Event-Id' => $eventId,        // e.g. "order-1001-created"; the same on every retry
        'X-HOK-Timestamp' => (string) $timestamp,
        'X-HOK-Signature' => $signature,
    ],
    'body' => $body,
    'http_errors' => false,
]);
```

In Node.js:

```js
const crypto = require('crypto');

const body = JSON.stringify(event);
const timestamp = Math.floor(Date.now() / 1000).toString();
const signature = 'v1=' + crypto.createHmac('sha256', signingSecret).update(`${timestamp}.${body}`).digest('hex');

await fetch(`https://hisabokitab.com/webhooks/channels/${connectionId}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-HOK-Event-Id': eventId, 'X-HOK-Timestamp': timestamp, 'X-HOK-Signature': signature },
  body,
});
```

With curl, for a first test:

```bash
BODY='{"type":"order.created","data":{"external_order_id":"TEST-1","placed_at":"2026-11-25T10:00:00+05:00","customer":{"name":"Test","phone":"03001234567"},"payment":{"method":"cod"},"items":[{"external_sku":"CHG-20W-BLK","name":"Charger","quantity":1,"unit_price":"1250.00"}]}}'
TS=$(date +%s)
SIG=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | sed 's/^.* //')
curl -X POST "https://hisabokitab.com/webhooks/channels/$CONNECTION_ID" \
  -H 'Content-Type: application/json' -H "X-HOK-Event-Id: test-1" \
  -H "X-HOK-Timestamp: $TS" -H "X-HOK-Signature: v1=$SIG" -d "$BODY"
```

### `order.created`

```json
{
  "type": "order.created",
  "data": {
    "external_order_id": "1001",
    "placed_at": "2026-11-07T14:25:00+05:00",
    "customer": {
      "name": "Sara Khan",
      "phone": "+923001234567",
      "address": "House 12, Street 4",
      "area": "Gulberg, Lahore"
    },
    "payment": { "method": "prepaid", "amount_paid": "2500.00" },
    "shipping_amount": "200.00",
    "discount_amount": "0.00",
    "note": "Call before delivery",
    "items": [
      { "product_id": 4211, "external_sku": "CHG-20W-BLK", "name": "Fast Charger 20W - Black", "quantity": 2, "unit_price": "1250.00" }
    ]
  }
}
```

- `external_order_id` is unique per connection. Sending it again changes nothing and returns the existing order.
- Each item needs `product_id` or `external_sku`. An item we can't match is still stored, and staff have to map or remove it before accepting.
- `unit_price` is what you charged. It's used on the sale, whatever the shop's listed price.
- `payment.method` is `prepaid` or `cod`. A COD order becomes a sale the customer owes until the shop records payment.
- `shipping_amount` is what the customer paid for delivery. For a shop registered as an online seller it's part of the sale (its total, revenue and what's owed). For any other shop it's kept with the order for reference only, and a prepaid sale records the goods total as paid.
- `phone` is required for `cod` orders.

### `order.cancelled`

```json
{ "type": "order.cancelled", "data": { "external_order_id": "1001", "reason": "Customer changed their mind" } }
```

A `pending` order is closed and its stock is released at once. An `accepted` order **isn't cancelled automatically**: `cancel_flagged` becomes `true` and shop staff decide. Don't assume the stock has come back until `available` changes.

This event is accepted while a connection is paused, so a customer's cancellation isn't lost. An unknown `external_order_id` gets a 404.

### Responses

| HTTP | Meaning |
|---|---|
| 200 | Stored, or already stored. `{ "data": { "external_order_id": "1001", "status": "pending" } }` |
| 401, 403, 404, 422 | See Errors. Don't retry until the cause is fixed. |
| 429, 5xx, or no response | Retry with the same `X-HOK-Event-Id` and body, with a fresh timestamp and signature. |

A 200 means we stored the order, not that the shop accepted it. An order is stored even if the shop is over a plan limit; staff just can't accept it until they have room.

## Events we send you

We `POST` to the HTTPS callback URL set on the connection, signed the same way with the same secret, with the same three headers. Verify the signature and timestamp before trusting an event. The URL must be public, on port 443, and resolve only to public addresses.

```json
{
  "id": "evt_01JC8ZK2",
  "type": "inventory.updated",
  "created_at": "2026-11-07T14:31:00+05:00",
  "data": {
    "items": [ { "product_id": 4211, "external_sku": "CHG-20W-BLK", "available": 10, "sequence": 90413 } ]
  }
}
```

| `type` | `data` |
|---|---|
| `inventory.updated` | `items`, as in `GET /inventory` |
| `product.updated` | A product, as in `GET /products` |
| `product.unpublished` | `{ "product_id": 4211, "external_sku": "CHG-20W-BLK" }`. Stop selling it. |
| `order.accepted` | `{ "external_order_id": "1001" }` |
| `order.rejected` | `{ "external_order_id": "1001", "reason": "Out of stock" }` |
| `order.fulfillment_changed` | `{ "external_order_id": "1001", "fulfillment_status": "packed" }` |
| `order.cancel_flagged` | `{ "external_order_id": "1001" }` |
| `connection.paused` | `{}`. **Stop selling. Published stock is no longer being kept up to date.** |
| `connection.resumed` | `{}`. Run a full `GET /inventory`, then resume. |

### Verifying our events

Check the signature before reading anything, against the raw body, and refuse a timestamp more than 5 minutes off:

```php
$raw = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_HOK_TIMESTAMP'] ?? '';
$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $signingSecret);

if (! hash_equals($expected, $_SERVER['HTTP_X_HOK_SIGNATURE'] ?? '') || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
// Skip $event['id'] if you've handled it before; then act on $event['type'].
http_response_code(200);
```

```js
// Express: use express.raw({ type: 'application/json' }) on this route so req.body is the raw Buffer.
const expected = 'v1=' + crypto.createHmac('sha256', signingSecret).update(`${req.get('X-HOK-Timestamp')}.${req.body}`).digest('hex');
const ok = expected.length === (req.get('X-HOK-Signature') || '').length
  && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.get('X-HOK-Signature')))
  && Math.abs(Date.now() / 1000 - Number(req.get('X-HOK-Timestamp'))) <= 300;
```

### Handling events

- **Respond with a 2xx within 10 seconds** and do slow work afterwards. Redirects count as failures.
- **Expect duplicates:** ignore an `id` you've already processed.
- **Expect events out of order:** use `sequence` for stock and fetch the order's current status for orders.
- **Don't rely on events alone:** one can be missed. Run `GET /inventory?updated_since=...` regularly. We also send a full stock push every night.
- We retry failures with growing delays for up to 24 hours. If an event still hasn't got through, we stop sending and alert the shop owner in HisabOKitab. Once it's fixed they start updates again, and you get a full stock push.
- While a connection is paused we send order events but no stock or product events. A full stock push follows `connection.resumed`.

## Overselling

Stock is shared with the shop's counter, so a last unit can sell in-store while your order is on its way. If so, staff can't accept the order and will reject it. Your store must be able to cancel and refund after a `rejected` status, and a shop's safety buffer reduces how often this happens.

## Changing the contract

Within v1 we only add: new fields, new event types and new optional parameters. Ignore what you don't recognise. A breaking change means a new version at `/v2`, with v1 kept running during a notice period.

## Rotating the signing secret

The owner can generate a new secret from the connection. Both are valid for 24 hours: switch to the new one in that time, for signing and for verifying. We sign outbound events with the new secret as soon as it's created.
