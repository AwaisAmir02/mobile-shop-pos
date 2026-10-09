<?php

namespace App\Support;

class SaleCartMath
{
    public static function lineTotal(array $item): float
    {
        $unitPrice = (float) $item['unit_price'];
        $quantity = (int) $item['quantity'];
        $discount = (float) ($item['discount'] ?? 0);

        return max(0.0, $unitPrice * $quantity - $discount);
    }

    public static function subtotal(array $cart): float
    {
        return array_sum(array_map(fn (array $item) => self::lineTotal($item), $cart));
    }

    public static function total(array $cart, float $invoiceDiscount): float
    {
        $subtotal = self::subtotal($cart);

        return max(0.0, $subtotal - min($invoiceDiscount, $subtotal));
    }

    /**
     * Splits one cart line across the batches FIFO drew it from. With no
     * override, each sub-line is charged at its own batch's selling price
     * (a line spanning two batches is genuinely split, never blended). With
     * an override, that single price applies uniformly across every
     * sub-line, and the discount is prorated by quantity so the sub-lines'
     * totals sum exactly to what one blended line would have totalled.
     *
     * @param  array<int, array{stock_in_id: ?int, quantity: int, cost_price: ?float, selling_price: float}>  $allocations
     * @return array<int, array{quantity: int, unit_price: float, list_price: float, cost_price: ?float, discount_amount: float, line_total: float, stock_in_id: ?int}>
     */
    public static function allocateLines(?float $overridePrice, float $discountTotal, array $allocations): array
    {
        $totalQuantity = array_sum(array_column($allocations, 'quantity'));
        $count = count($allocations);
        $lines = [];
        $discountAllocated = 0.0;

        foreach ($allocations as $index => $allocation) {
            $listPrice = (float) $allocation['selling_price'];
            $unitPrice = $overridePrice ?? $listPrice;

            $isLast = $index === $count - 1;
            $discountShare = $isLast
                ? round($discountTotal - $discountAllocated, 2)
                : round($discountTotal * ($allocation['quantity'] / $totalQuantity), 2);
            $discountAllocated += $discountShare;

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

        return $lines;
    }
}
