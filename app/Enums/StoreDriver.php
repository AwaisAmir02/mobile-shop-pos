<?php

namespace App\Enums;

/**
 * How a store connection talks to its store. All of them share the same core:
 * the published-product mapping (store_products), stock from StockAvailability,
 * the change outbox (StoreChanges), the order inbox (StoreOrders) and acceptance
 * through CreateSale.
 *
 *  - Api: the shop's own developer uses our contract (docs/channel-api-v1.md):
 *    API token + signed webhooks both ways.
 *  - Shopify: our Shopify app, installed by OAuth; orders arrive as Shopify
 *    webhooks, stock is set through the Admin GraphQL API (App\Storefronts\Shopify).
 *  - WooCommerce: keys granted through WooCommerce's own app-authorisation page;
 *    orders arrive as WooCommerce webhooks, stock is set through its REST API
 *    (App\Storefronts\WooCommerce).
 *  - Daraz: no API yet (that needs Daraz's app approval). Orders and payout
 *    statements are imported from Seller Center files (App\Storefronts\DarazImports).
 */
enum StoreDriver: string
{
    case Api = 'api';
    case Shopify = 'shopify';
    case WooCommerce = 'woocommerce';
    case Daraz = 'daraz';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Own website (API)',
            self::Shopify => 'Shopify',
            self::WooCommerce => 'WooCommerce',
            self::Daraz => 'Daraz',
        };
    }

    /** Where a sale accepted from this connection came from. */
    public function saleSource(): SaleSource
    {
        return $this === self::Daraz ? SaleSource::Daraz : SaleSource::Online;
    }

    /** Stock and orders move by API calls to the platform, not by our signed callbacks. */
    public function isPlatform(): bool
    {
        return in_array($this, [self::Shopify, self::WooCommerce], true);
    }

    /** The marketplace delivers and collects, then pays the shop net of its fees (O6). */
    public function isMarketplace(): bool
    {
        return $this === self::Daraz;
    }
}
