<?php

namespace App\Storefronts;

use RuntimeException;

/** A Shopify or WooCommerce call that didn't work, in words fit to show the shop. */
class StorefrontException extends RuntimeException
{
}
