<?php

namespace App\Enums;

/**
 * How the customer said they will pay, chosen in the WhatsApp chat. This is
 * only what they told us — staff still record the actual payment on accept.
 */
enum WhatsAppPaymentChoice: string
{
    case CashOnDelivery = 'cod';
    case PayAtShop = 'pay_at_shop';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Cash on delivery',
            self::PayAtShop => 'Pay at shop',
            self::Online => 'Online transfer',
        };
    }

    public function isCash(): bool
    {
        return $this !== self::Online;
    }
}
