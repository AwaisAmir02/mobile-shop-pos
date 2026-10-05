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
}
