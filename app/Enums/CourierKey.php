<?php

namespace App\Enums;

use App\Couriers\CourierProvider;
use App\Couriers\Providers\LeopardsProvider;
use App\Couriers\Providers\PostExProvider;

/**
 * Pakistan's couriers. Each shop connects its OWN merchant account with a
 * courier; we call that courier's API with the shop's credentials and never
 * handle the courier's money.
 *
 * Only couriers with a provider class can be connected. The others are
 * listed so shops see what's coming, with the honest reason it isn't yet.
 */
enum CourierKey: string
{
    case Leopards = 'leopards';
    case PostEx = 'postex';
    case Tcs = 'tcs';
    case Trax = 'trax';
    case MnP = 'mnp';

    public function label(): string
    {
        return match ($this) {
            self::Leopards => 'Leopards Courier',
            self::PostEx => 'PostEx',
            self::Tcs => 'TCS',
            self::Trax => 'Trax',
            self::MnP => 'M&P',
        };
    }

    /** @return class-string<CourierProvider>|null */
    public function providerClass(): ?string
    {
        return match ($this) {
            self::Leopards => LeopardsProvider::class,
            self::PostEx => PostExProvider::class,
            default => null,
        };
    }

    public function isAvailable(): bool
    {
        return $this->providerClass() !== null;
    }

    /** Why a courier can't be connected yet, in words for the shop. */
    public function unavailableReason(): ?string
    {
        return match ($this) {
            self::Tcs => 'TCS gives API access only after their e-commerce team sets it up for your account. Coming once we have their booking API confirmed.',
            self::Trax => 'Trax\'s booking API isn\'t publicly documented yet. Coming once Trax confirms it.',
            self::MnP => 'M&P has no public API documentation. Coming once M&P gives us access.',
            default => null,
        };
    }

    /** @return array<int, self> */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $courier) => $courier->isAvailable()));
    }

    public function provider(): CourierProvider
    {
        $class = $this->providerClass() ?? throw new \LogicException("{$this->label()} can't be connected yet.");

        return app($class);
    }
}
