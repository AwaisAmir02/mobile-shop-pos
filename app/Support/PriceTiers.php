<?php

namespace App\Support;

use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;

/**
 * The second price a wholesale business sells at (W3).
 *
 * A product can carry a trade price (wholesale_price) beside its normal
 * selling price. A customer buys at the trade price unless their own price
 * level says "normal"; walk-in sales (no customer) stay at the normal price.
 * Only wholesale businesses (Shop::isWholesale) use this: for every other shop
 * nothing here runs and the register prices exactly as before.
 *
 * A trade price is a default, never a lock: the cashier's own price on a line
 * still wins, as it always has. The register and the order form both price
 * through priceFor(), so a piece, a pack and an outer pack cost the same in
 * either.
 */
final class PriceTiers
{
    public const PIECE = 'piece';

    public const PACK = 'pack';

    public const OUTER = 'outer';

    /** The level a customer buys at: their own choice, else the trade price. */
    public static function forCustomer(?Customer $customer): PriceTier
    {
        if ($customer === null) {
            return PriceTier::Normal;
        }

        return $customer->price_tier === PriceTier::Normal ? PriceTier::Normal : PriceTier::Trade;
    }

    /**
     * One piece, pack or outer pack of $product for $customer: the price, and
     * the level it came from (null when the normal selling price applies).
     * A pack or outer pack at the trade price is the trade price per piece
     * times its pieces, never above the pack's own normal price, since a
     * carton price is often already a bulk price.
     *
     * @param  string  $level  self::PIECE, self::PACK or self::OUTER
     * @return array{price: float, tier: ?PriceTier}
     */
    public static function priceFor(Product $product, ?Customer $customer, string $level = self::PIECE): array
    {
        $tier = self::forCustomer($customer);

        [$normal, $pieces] = match ($level) {
            self::OUTER => [$product->outerPackSellingPrice(), $product->outerPackPieces()],
            self::PACK => [$product->packSellingPrice(), (int) $product->pack_size],
            default => [$product->currentSellingPrice(), 1],
        };

        $piece = $tier === PriceTier::Trade && $product->wholesale_price !== null ? round((float) $product->wholesale_price, 2) : null;

        if ($piece === null) {
            return ['price' => $normal, 'tier' => null];
        }

        return ['price' => $level === self::PIECE ? $piece : min(round($normal, 2), round($piece * $pieces, 2)), 'tier' => $tier];
    }

    /** What the trade price is called in this trade: wholesale price, trade price (TP)… */
    public static function label(?Shop $shop): string
    {
        return $shop?->business_category?->tradePriceLabel() ?? 'Wholesale price';
    }

    /** @return array<string, string> a customer's price level choices, for a form ('' = trade price, the default) */
    public static function options(?Shop $shop): array
    {
        return ['' => self::label($shop).' (default)', PriceTier::Normal->value => 'Normal selling price'];
    }
}
