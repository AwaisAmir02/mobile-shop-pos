<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Promotion;
use App\Support\Quantity;
use App\Support\SaleCartMath;
use Illuminate\Support\Collection;

/**
 * Promotions on a sale line (v1).
 *
 * A line gets at most one promotion, the one that takes the most off it
 * (ties go to the older promotion). It is worked out on what the line comes
 * to after its own discount, so a cashier's discount and a promotion add up
 * rather than multiply. The promotion becomes part of the line's discount:
 * discount_amount holds both, and promotion_discount says how much of it
 * was the promotion, split across the line's batches exactly like the rest.
 *
 * Only the register asks for promotions (CreateSale's applyPromotions).
 * WhatsApp and online orders don't, and an edit only keeps the promotion a
 * line already had.
 */
class Promotions
{
    /** @return Collection<int, Promotion> running in the shop on that day */
    public static function runningOn(int $shopId, string $date): Collection
    {
        return Promotion::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderBy('id')
            ->get();
    }

    /**
     * The sub-lines CreateSale stores for one product line, with the
     * promotion on them. $kept is the promotion an edited line already had;
     * it stays whatever its dates now say.
     *
     * @param  Collection<int, Promotion>|null  $running
     * @param  array<int, array<string, mixed>>  $allocations  ConsumeStockFifo output
     * @return array<int, array<string, mixed>> SaleCartMath::allocateLines() rows plus promotion_id, promotion_name, promotion_discount
     */
    public static function priceLine(Product $product, ?float $customPrice, float $discount, array $allocations, ?Collection $running, ?Promotion $kept = null): array
    {
        $lines = SaleCartMath::allocateLines($customPrice, $discount, $allocations);
        $amount = round(array_sum(array_column($lines, 'line_total')), 2);
        $quantity = Quantity::sum(array_column($allocations, 'quantity'));

        [$promotion, $off] = self::best($kept !== null ? collect([$kept]) : ($running ?? collect())->filter->covers($product), $amount, $quantity);

        if ($promotion === null) {
            return array_map(fn (array $line) => $line + ['promotion_id' => null, 'promotion_name' => null, 'promotion_discount' => 0.0], $lines);
        }

        $promoted = SaleCartMath::allocateLines($customPrice, round($discount + $off, 2), $allocations);

        foreach ($promoted as $i => $line) {
            $promoted[$i] += [
                'promotion_id' => $promotion->id,
                'promotion_name' => $promotion->name,
                'promotion_discount' => round($line['discount_amount'] - $lines[$i]['discount_amount'], 2),
            ];
        }

        return $promoted;
    }

    /**
     * A line with free scheme goods (W9): the bought and free pieces are all
     * in $allocations; the free ones' share of the line's value comes off as
     * discount, so the line totals what the bought pieces cost. No promotion
     * is added on top of a scheme.
     */
    public static function priceLineWithFree(Product $product, ?float $customPrice, float $discount, array $allocations, int|float $free): array
    {
        $plain = SaleCartMath::allocateLines($customPrice, 0.0, $allocations);
        $gross = (float) array_sum(array_column($plain, 'line_total'));
        $quantity = (float) Quantity::sum(array_column($allocations, 'quantity'));
        $freeValue = $quantity > 0 ? round($gross * (float) $free / $quantity, 2) : 0.0;

        return array_map(
            fn (array $line) => $line + ['promotion_id' => null, 'promotion_name' => null, 'promotion_discount' => 0.0],
            SaleCartMath::allocateLines($customPrice, round($discount + $freeValue, 2), $allocations),
        );
    }

    /**
     * @param  Collection<int, Promotion>  $candidates
     * @return array{0: ?Promotion, 1: float}
     */
    public static function best(Collection $candidates, float $amount, int|float $quantity): array
    {
        $best = [null, 0.0];

        foreach ($candidates as $promotion) {
            $off = $promotion->discountOn($amount, $quantity);

            if ($off > $best[1]) {
                $best = [$promotion, $off];
            }
        }

        return $best;
    }
}
