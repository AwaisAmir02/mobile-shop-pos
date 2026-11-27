<?php

namespace App\Enums;

/**
 * What one line of a sale is. Every line before the Services round is a
 * product; the rest come from App\Actions\ServiceLines and never touch stock.
 */
enum SaleLineKind: string
{
    /** Goods from stock (the only kind there was before). */
    case Product = 'product';
    /** A service off the menu (a haircut, labour on a job), with who did it. */
    case Service = 'service';
    /** A membership or package bought on this bill; the Membership is made from it. */
    case Membership = 'membership';
    /** A session taken out of a package bought earlier: no charge on this bill. */
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
            self::Membership => 'Membership',
            self::Package => 'From package',
        };
    }

    /** Only a product takes stock, can be edited on the sale, or comes back into stock on a return. */
    public function isProduct(): bool
    {
        return $this === self::Product;
    }

    /** Returned for money without restocking. Memberships and package sessions are undone by cancelling the sale. */
    public function isReturnable(): bool
    {
        return $this === self::Product || $this === self::Service;
    }
}
