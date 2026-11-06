<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class SaleCartMath
{
    /** A line's total is settled to the paisa here, so every later sum adds whole paisas. */
    public static function lineTotal(array $item): float
    {
        return Quantity::money($item['unit_price'], $item['quantity'], $item['discount'] ?? 0);
    }

    public static function subtotal(array $cart): float
    {
        return round(array_sum(array_map(fn (array $item) => self::lineTotal($item), $cart)), 2);
    }

    public static function total(array $cart, float $invoiceDiscount): float
    {
        $subtotal = self::subtotal($cart);

        return round(max(0.0, $subtotal - min($invoiceDiscount, $subtotal)), 2);
    }

    /**
     * Splits one cart line across the batches FIFO drew it from. With no
     * override, each sub-line is charged at its own batch's selling price
     * (a line spanning two batches is genuinely split, never blended). With
     * an override, that single price applies uniformly across every
     * sub-line, and the discount is prorated by quantity so the sub-lines'
     * totals sum exactly to what one blended line would have totalled.
     *
     * Rounding each sub-line can leave them a paisa away from the whole line
     * once quantities are fractional, so the last one absorbs the difference.
     *
     * @param  array<int, array{stock_in_id: ?int, quantity: int|float, cost_price: ?float, selling_price: float}>  $allocations
     * @return array<int, array{quantity: int|float, unit_price: float, list_price: float, cost_price: ?float, discount_amount: float, line_total: float, stock_in_id: ?int}>
     */
    public static function allocateLines(?float $overridePrice, float $discountTotal, array $allocations): array
    {
        $totalThousandths = array_sum(array_map(fn (array $allocation) => Quantity::toThousandths($allocation['quantity']), $allocations));
        $count = count($allocations);
        $lines = [];
        $discountAllocated = 0.0;
        $exactTotal = BigDecimal::zero();
        $clamped = false;

        foreach ($allocations as $index => $allocation) {
            $listPrice = (float) $allocation['selling_price'];
            $unitPrice = $overridePrice ?? $listPrice;

            $isLast = $index === $count - 1;
            $discountShare = $isLast
                ? round($discountTotal - $discountAllocated, 2)
                : round($discountTotal * (Quantity::toThousandths($allocation['quantity']) / $totalThousandths), 2);
            $discountAllocated += $discountShare;

            $exact = Quantity::exactAmount($unitPrice, $allocation['quantity']);
            $exactTotal = $exactTotal->plus($exact);
            $clamped = $clamped || $exact->minus(Quantity::decimal($discountShare))->isNegative();

            $lines[] = [
                'quantity' => $allocation['quantity'],
                'unit_price' => $unitPrice,
                'list_price' => $listPrice,
                'cost_price' => $allocation['cost_price'],
                'discount_amount' => $discountShare,
                'line_total' => self::lineTotal(['unit_price' => $unitPrice, 'quantity' => $allocation['quantity'], 'discount' => $discountShare]),
                'stock_in_id' => $allocation['stock_in_id'],
            ];
        }

        // A sub-line floored at zero by a discount bigger than itself has no blended figure to match.
        if ($count > 1 && ! $clamped) {
            $whole = $exactTotal->minus(Quantity::decimal($discountTotal))->toScale(2, RoundingMode::HALF_UP)->toFloat();
            $others = round(array_sum(array_column(array_slice($lines, 0, -1), 'line_total')), 2);
            $lastTotal = round($whole - $others, 2);

            if ($lastTotal >= 0) {
                $lines[$count - 1]['line_total'] = $lastTotal;
            }
        }

        return $lines;
    }
}
