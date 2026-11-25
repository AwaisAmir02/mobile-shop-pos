<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;

/**
 * The second price a wholesale business sells at (W3).
 *
 * A product can carry a trade price (wholesale_price) beside its normal
 * selling price. A customer buys at the trade price unless their own price
 * level says "normal"; walk-in sales (no customer) stay at the normal price.
 * Only wholesale businesses use this: for every other shop nothing here runs
 * and the register prices exactly as before.
 *
 * A trade price is a default, never a lock: the cashier's own price on a line
 * still wins, as it always has.
 */
final class PriceTiers
{
    /** The customer buys at the product's trade price, when it has one. */
    public const TRADE = 'trade';

    /** The customer buys at the normal selling price. */
    public const NORMAL = 'normal';

    public static function enabled(?Shop $shop): bool
    {
        return (bool) $shop?->isWholesale();
    }

    /** The level a customer buys at: their own choice, else the trade price. */
    public static function forCustomer(?Customer $customer): string
    {
        if ($customer === null) {
            return self::NORMAL;
        }

        return $customer->price_tier === self::NORMAL ? self::NORMAL : self::TRADE;
    }

    /** One piece (or kg…) at that level, or null when the normal price applies. */
    public static function piecePrice(Product $product, string $tier): ?float
    {
        return $tier === self::TRADE && $product->wholesale_price !== null
            ? round((float) $product->wholesale_price, 2)
            : null;
    }

    /**
     * A whole pack (or outer pack) of $pieces at that level: the trade price
     * per piece times the pieces, but never above the pack's own normal price,
     * since a carton price is often already a bulk price. Null = normal price.
     */
    public static function packPrice(Product $product, string $tier, int $pieces, float $normalPackPrice): ?float
    {
        $piece = self::piecePrice($product, $tier);

        return $piece === null ? null : min(round($normalPackPrice, 2), round($piece * $pieces, 2));
    }

    /** What the trade price is called in this trade: wholesale price, trade price (TP)… */
    public static function label(?Shop $shop): string
    {
        return $shop?->business_category?->tradePriceLabel() ?? 'Wholesale price';
    }

    /** @return array<string, string> a customer's price level choices, for a form ('' = trade price, the default) */
    public static function options(?Shop $shop): array
    {
        return ['' => self::label($shop).' (default)', self::NORMAL => 'Normal selling price'];
    }
}
