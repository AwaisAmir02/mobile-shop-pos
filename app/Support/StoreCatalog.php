<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use App\Services\Billing\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** A product as a store sees it, whether it asked or was told. */
class StoreCatalog
{
    /** @param  array<int, float>  $prices  */
    public static function productData(StoreProduct $row, array $prices): array
    {
        $product = $row->product;
        $group = $product->group;
        $updated = collect([$row->updated_at, $product->updated_at, $group?->updated_at])->filter()->max();

        return [
            'id' => $product->id,
            'external_sku' => $row->external_sku,
            'name' => $product->name,
            'group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name, 'variant_label' => $product->variant_label],
            'barcode' => $product->barcode,
            'category' => $product->typeLabel(),
            'image_url' => $product->imageUrl(),
            'price' => isset($prices[$product->id]) ? number_format($prices[$product->id], 2, '.', '') : null,
            'published' => $row->isOnSale(),
            'updated_at' => self::time($updated),
        ];
    }

    /**
     * The next batch's price, only for a shop that chose to sync it.
     *
     * @return array<int, float>
     */
    public static function prices(StoreConnection $connection, Collection $rows): array
    {
        return $connection->price_sync && $rows->isNotEmpty() ? Product::sellingPricesFor($rows->pluck('product')) : [];
    }

    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->copy()->timezone(Entitlements::TIMEZONE)->toIso8601String();
    }
}
