<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Support\ApiShopContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function ($model) {
            if (($apiShopId = ApiShopContext::current()->shopId()) !== null) {
                if ($model->shop_id && (int) $model->shop_id !== $apiShopId) {
                    throw new \RuntimeException('An API request tried to write to another shop.');
                }

                $model->shop_id = $apiShopId;

                return;
            }

            if (! $model->shop_id && Auth::check()) {
                $model->shop_id = Auth::user()->shop_id;
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
