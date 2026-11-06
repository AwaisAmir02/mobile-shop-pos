<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Stock and sale quantities, held to three decimals. All arithmetic runs in
 * whole thousandths: raw PHP floats leave a tiny residue (0.1 + 0.2 - 0.3 is
 * not zero), which FIFO would turn into a false "uncovered" remainder.
 * Whole values always come back as plain integers.
 */
final class Quantity
{
    public const SCALE = 1000;

    public const DECIMALS = 3;

    public const MAX = 999999999.999;

    /** Keeps nonsense input from overflowing an integer; validation rejects it anyway. */
    protected const CLAMP = 9_000_000_000_000_000;

    /** Non-numeric input (an emptied form field, say) counts as nothing. */
    public static function toThousandths(mixed $quantity): int
    {
        if (is_int($quantity) && abs($quantity) < self::CLAMP / self::SCALE) {
            return $quantity * self::SCALE;
        }

        if (! is_numeric($quantity)) {
            return 0;
        }

        return (int) round(max(-self::CLAMP, min(self::CLAMP, (float) $quantity * self::SCALE)));
    }

    public static function fromThousandths(int $thousandths): int|float
    {
        return $thousandths % self::SCALE === 0 ? intdiv($thousandths, self::SCALE) : $thousandths / self::SCALE;
    }

    public static function normalize(mixed $quantity): int|float
    {
        return self::fromThousandths(self::toThousandths($quantity));
    }

    public static function isWhole(mixed $quantity): bool
    {
        return self::toThousandths($quantity) % self::SCALE === 0;
    }

    /** Whether a value fits in three decimals, so storing it loses nothing. */
    public static function fitsScale(mixed $quantity): bool
    {
        if (! is_numeric($quantity)) {
            return false;
        }

        $scaled = (float) $quantity * self::SCALE;

        return abs($scaled - round($scaled)) < 0.000001;
    }

    public static function add(mixed $a, mixed $b): int|float
    {
        return self::fromThousandths(self::toThousandths($a) + self::toThousandths($b));
    }

    public static function subtract(mixed $a, mixed $b): int|float
    {
        return self::fromThousandths(self::toThousandths($a) - self::toThousandths($b));
    }

    public static function sum(iterable $quantities): int|float
    {
        $total = 0;

        foreach ($quantities as $quantity) {
            $total += self::toThousandths($quantity);
        }

        return self::fromThousandths($total);
    }

    /** Negative, zero or positive, as a is below, equal to or above b. */
    public static function compare(mixed $a, mixed $b): int
    {
        return self::toThousandths($a) <=> self::toThousandths($b);
    }

    /** "1.5", "5": what a person would write, with no trailing zeros. */
    public static function plain(mixed $quantity): string
    {
        return self::trimmed(number_format((float) self::normalize($quantity), self::DECIMALS, '.', ''));
    }

    /** "1,234.5", "1,234": plain() with thousands separators. */
    public static function grouped(mixed $quantity): string
    {
        return self::trimmed(number_format((float) self::normalize($quantity), self::DECIMALS));
    }

    /** price × quantity − discount, exact, rounded half up to the paisa, never below zero. */
    public static function money(mixed $price, mixed $quantity, mixed $discount = 0): float
    {
        $amount = self::exactAmount($price, $quantity)->minus(self::decimal($discount));

        return max(0.0, $amount->toScale(2, RoundingMode::HALF_UP)->toFloat());
    }

    public static function exactAmount(mixed $price, mixed $quantity): BigDecimal
    {
        return self::decimal($price)->multipliedBy(BigDecimal::ofUnscaledValue(self::toThousandths($quantity), self::DECIMALS));
    }

    public static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of(sprintf('%.6F', is_numeric($value) ? (float) $value : 0.0));
    }

    protected static function trimmed(string $number): string
    {
        return rtrim(rtrim($number, '0'), '.');
    }
}
