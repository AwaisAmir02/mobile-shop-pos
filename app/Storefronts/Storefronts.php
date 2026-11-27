<?php

namespace App\Storefronts;

use App\Enums\StoreDriver;
use LogicException;

/** The platform behind a store connection's driver. */
class Storefronts
{
    public function platform(StoreDriver $driver): PlatformStore
    {
        return match ($driver) {
            StoreDriver::Shopify => app(Shopify::class),
            StoreDriver::WooCommerce => app(WooCommerce::class),
            default => throw new LogicException("{$driver->label()} isn't a store platform."),
        };
    }
}
