<?php

namespace App\Enums;

/**
 * Payment gateways a shop can take customer payments through, each with the
 * shop's OWN merchant account. The money goes straight from the customer to
 * the shop's merchant account; it never passes through us.
 */
enum PaymentGateway: string
{
    case JazzCash = 'jazzcash';
    case Easypaisa = 'easypaisa';

    public function label(): string
    {
        return match ($this) {
            self::JazzCash => 'JazzCash',
            self::Easypaisa => 'Easypaisa',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::JazzCash;
    }

    public function unavailableReason(): ?string
    {
        return match ($this) {
            self::Easypaisa => 'Easypaisa shares its checkout and payment-check details only with approved merchants, so we can\'t confirm a payment safely yet. Coming once Easypaisa gives us their merchant integration guide. Customers with Easypaisa can still pay by card through JazzCash.',
            default => null,
        };
    }
}
