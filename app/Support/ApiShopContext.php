<?php

namespace App\Support;

/**
 * The shop an API request belongs to. An API request has no signed-in user, and
 * ShopScope would otherwise do nothing, leaving every query open to every shop.
 * While run() is going, ShopScope and BelongsToShop use this shop instead. Unset,
 * as it is everywhere outside the API, it changes nothing.
 */
class ApiShopContext
{
    protected ?int $shopId = null;

    public static function current(): self
    {
        return app(self::class);
    }

    public function shopId(): ?int
    {
        return $this->shopId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(int $shopId, callable $callback): mixed
    {
        $previous = $this->shopId;
        $this->shopId = $shopId;

        try {
            return $callback();
        } finally {
            $this->shopId = $previous;
        }
    }
}
