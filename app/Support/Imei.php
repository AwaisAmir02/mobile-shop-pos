<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Scopes\ShopScope;
use Closure;

/**
 * A phone's IMEI: exactly 15 digits, the last one a Luhn check digit.
 * Spaces, dashes and dots that people type or paste are dropped first.
 *
 * Used wherever an IMEI is typed (product form, quick-create, Excel import).
 * Stored products aren't re-checked: an IMEI saved before this rule existed
 * stays as it is until someone changes it.
 */
final class Imei
{
    public static function normalize(?string $value): string
    {
        return preg_replace('/[\s\-.]+/u', '', trim((string) $value)) ?? '';
    }

    public static function isValid(string $digits): bool
    {
        if (! preg_match('/^\d{15}$/', $digits)) {
            return false;
        }

        $sum = 0;

        foreach (str_split($digits) as $i => $digit) {
            $d = (int) $digit;

            // Luhn: double every second digit counting from the left's second place.
            if ($i % 2 === 1) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }

            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    /** Why $digits isn't an IMEI, or null when it is one. */
    public static function problem(string $digits): ?string
    {
        if (! preg_match('/^\d+$/', $digits)) {
            return 'An IMEI has digits only.';
        }

        if (strlen($digits) !== 15) {
            return 'An IMEI is exactly 15 digits; this has '.strlen($digits).'. Dial *#06# on the phone or check the box.';
        }

        return self::isValid($digits) ? null : 'This isn\'t a valid IMEI (the last digit doesn\'t match). Check it for a typo.';
    }

    /**
     * The product in this shop that already has $digits, sold, archived or
     * not, other than $ignoreProductId. Compared in PHP after normalizing, so
     * "35 6789…" and "356789…" are the same IMEI.
     */
    public static function owner(int $shopId, string $digits, ?int $ignoreProductId = null): ?Product
    {
        if ($digits === '') {
            return null;
        }

        return Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNotNull('details->imei')
            ->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))
            ->get(['id', 'name', 'details', 'archived_at', 'stock_quantity', 'unit'])
            ->first(fn (Product $p) => self::normalize($p->details['imei'] ?? '') === $digits);
    }

    public static function describeOwner(Product $product): string
    {
        $state = match (true) {
            $product->archived_at !== null => ' (archived)',
            (float) $product->stock_quantity <= 0 => ' (sold or out of stock)',
            default => '',
        };

        return '"'.$product->name.'"'.$state;
    }

    /**
     * Validation for a typed IMEI. $current is what the product already has:
     * leaving it unchanged is always allowed, so older entries can still be edited.
     */
    public static function rule(int $shopId, ?int $ignoreProductId = null, ?string $current = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($shopId, $ignoreProductId, $current) {
            $digits = self::normalize((string) $value);

            if ($digits === '' || ($current !== null && $digits === self::normalize($current))) {
                return;
            }

            if (($problem = self::problem($digits)) !== null) {
                $fail($problem);

                return;
            }

            if (($owner = self::owner($shopId, $digits, $ignoreProductId)) !== null) {
                $fail('This IMEI is already on '.self::describeOwner($owner).'.');
            }
        };
    }
}
