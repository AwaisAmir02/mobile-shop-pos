# Store platforms and Daraz (Online P2)

How a shop's Shopify store, WooCommerce store and Daraz account connect, what we
set up once on our side, and what has and hasn't been tried against the real
platforms. The own-website API is docs/channel-api-v1.md.

All three sit on the store connector everything else uses: products mapped in
`store_products`, stock from `StockAvailability` (less the connection's buffer),
the change outbox (`StoreChanges` → `StoreDelivery`), and the order inbox
(`StoreOrders`), where an order becomes a sale only through `CreateSale`.
HisabOKitab is the only truth for stock; no platform can change it.

## Shopify

**One-time setup (us).** Create one app in the Shopify Partner Dashboard (or the
Dev Dashboard) and set:

| Setting | Value |
|---|---|
| App URL | `https://hisabokitab.com` |
| Allowed redirection URL | `https://hisabokitab.com/storefronts/shopify/callback` |
| Compliance webhooks (customers/data_request, customers/redact, shop/redact) | `https://hisabokitab.com/webhooks/shopify` |
| Scopes | `read_products,read_inventory,write_inventory,read_orders,read_locations` |

Put its Client ID and Client secret in `SHOPIFY_CLIENT_ID` / `SHOPIFY_CLIENT_SECRET`.
Keep `SHOPIFY_API_VERSION` on a version Shopify still supports (each lasts about a
year). Since 2026-04 `inventorySetQuantities` wants `changeFromQuantity` on every
quantity; we send `null` (set outright), which is right for the source of truth.
Read orders more than 60 days old would need `read_all_orders`; we don't.

To list the app publicly, Shopify reviews it; until then it can be installed on
stores through a custom distribution link. Installs must start from our Settings
(the state ties the install to the shop); an install from the App Store listing
can't be matched to a shop yet.

**What the shop does.** Settings › Integrations › Online Store › *Shopify or
WooCommerce store*: type `mystore.myshopify.com`, press Connect, approve on
Shopify. Back here we:

1. check Shopify's `hmac` (hex HMAC-SHA256 of the other query parameters, sorted,
   with the client secret) and our one-time `state`;
2. exchange the code for an offline access token (`POST /admin/oauth/access_token`),
   kept encrypted on the connection;
3. take the store's first location for stock;
4. subscribe `ORDERS_CREATE`, `ORDERS_CANCELLED`, `APP_UNINSTALLED`
   (`webhookSubscriptionCreate`, Admin GraphQL);
5. read every variant (`productVariants`) and map it by SKU: an earlier mapping,
   else a product whose **barcode** is that SKU. Unmatched SKUs are listed to map
   by hand. Mapped products are published, and their stock is sent at once.

**Orders.** `orders/create` arrives signed (`X-Shopify-Hmac-Sha256`, base64 HMAC of
the raw body with the client secret), is mapped into the contract's order shape
(`App\Storefronts\Shopify::order`) and lands in Online Orders as pending.
`financial_status: paid` is prepaid; anything else (COD, bank transfer pending) is
unpaid. Shipping is the shipping lines' total; for an online seller it's part of
the sale. `orders/cancelled` closes a pending order or flags an accepted one.
`app/uninstalled` and `shop/redact` forget the token; `customers/redact` blanks
that customer's details on the orders named.

**Stock.** The outbox's `inventory.updated` events are sent with
`inventorySetQuantities` (name `available`, reason `correction`, up to 250 per
call). Product, price and order-status events have nothing to tell Shopify and
count as delivered.

## WooCommerce

**No setup on our side.** Each store owner approves our app on their own store.

**What the shop does.** Type the store's `https://` address, press Connect, log in
to WordPress if asked, press Approve. WooCommerce (`/wc-auth/v1/authorize`, scope
`read_write`) POSTs the new keys as JSON to `/webhooks/woocommerce-keys` with our
one-time state in `user_id`; we answer 200 at once (calling the store from that
request isn't allowed). The owner is then sent back to us, and we register
webhooks (`POST /wp-json/wc/v3/webhooks`, topics `order.created` and
`order.updated`, our own secret) and read the catalogue (products and the
variations of variable products), mapped by SKU as for Shopify.

The store must be reachable over https on a public address: every call goes
through `StoreCallbackGuard`, pinned to the checked address, no redirects.

**Orders.** Signed with `X-WC-Webhook-Signature` (base64 HMAC of the raw body with
the webhook secret). Imported once `processing`, `on-hold` or `completed` (a
`pending` order is still at checkout); `cancelled`, `refunded` or `failed` cancels.
`payment_method: cod` is unpaid; paid online (`date_paid`) is prepaid. The first
"ping" a new webhook sends (`webhook_id=…`) is answered and ignored.

**Stock.** `POST /products/batch` (and `/products/{id}/variations/batch` for
variations), `manage_stock: true`, up to 100 per call.

## Daraz

Daraz's API (Daraz Open Platform) needs an app approved by Daraz, so P2 works from
the files sellers already download. Daraz publishes no specification of these
files; its Seller Center is Lazada's platform, and the importer finds columns by
name from a list of aliases covering both the Title Case headers and the API's
field names (`App\Storefronts\DarazFiles`). A file missing a required column is
refused, naming the columns it had. **Check the first real file from a seller
before relying on this.**

**Orders file** (Orders › Manage Orders › Export). Required: Order Number, Seller
SKU, Status. Used if present: Order Item Id, Item Name, Unit Price, Paid Price,
Quantity, Created at, Customer/Shipping Name, Shipping Phone Number, Shipping
Address, Shipping City, Payment Method, Tracking Code. Rows (one per item, or one
per piece) are grouped by order and summed by SKU. A fully matched order is
accepted at once (it's already confirmed on Daraz) and becomes a parcel held by
Daraz, owed by Daraz, not a customer. Later files move parcels to delivered or
returned, or cancel. Importing a file twice changes nothing.

**Payout statement** (Finance › Account Statement / Transaction Overview). Required:
Amount, and Fee Name or Transaction Type; Order No. ties a line to an order. Item
price lines are what Daraz credits; tax lines (WHT, withholding, VAT, GST) are tax
withheld; everything else on an order is a charge, and lines with no order are
other deductions (credits lower them). A WHT column marked "not included in
Amount" is taken off too. The preview shows our net beside the file's own total
before anything is recorded; recording makes one courier payout
(`CourierSettlements`) from Daraz, exactly as a courier's.

Fulfilled-by-Daraz (FBD) stock isn't separated: stock is one pool here.
