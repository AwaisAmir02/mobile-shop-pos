<?php

namespace App\Actions;

use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Services\BarcodeService;
use Closure;
use Illuminate\Validation\Rule;

class CreateProduct
{
    public static function rules(int $shopId, string $type, ?int $ignoreProductId = null): array
    {
        $mainCategoryId = MainCategory::where('slug', $type)->where('shop_id', $shopId)->value('id');

        $rules = [
            'type' => ['required', 'string', Rule::exists('main_categories', 'slug')->where('shop_id', $shopId)],
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'category' => [
                $type === 'accessory' ? 'required' : 'nullable',
                'string', 'max:255',
                Rule::exists('accessory_category_options', 'name')->where('shop_id', $shopId)->where('main_category_id', $mainCategoryId),
            ],
        ];

        if ($type === 'mobile') {
            $rules['brand'] = ['required', 'string', 'max:255', Rule::exists('brands', 'name')->where('shop_id', $shopId)];
            $rules['model'] = ['nullable', 'string', 'max:255'];
            $rules['imei'] = ['nullable', 'string', 'max:50'];
        }

        if (Shop::whereKey($shopId)->value('barcode_enabled')) {
            $rules['barcode'] = ['nullable', 'string', 'max:64', self::barcodeRule($shopId, $ignoreProductId)];
        }

        return $rules;
    }

    public static function barcodeRule(int $shopId, ?int $ignoreProductId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($shopId, $ignoreProductId) {
            if ($value === null || $value === '') {
                return;
            }

            if (! preg_match('/^[\x20-\x7E]+$/', (string) $value)) {
                $fail('Barcode may only contain plain letters, digits and symbols.');

                return;
            }

            $message = BarcodeService::duplicateMessageFor($shopId, (string) $value, $ignoreProductId);

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    public static function detailsForType(string $type, string $category, string $brand = '', string $model = '', string $imei = ''): array
    {
        $details = ['category' => $category !== '' ? $category : null];

        if ($type === 'mobile') {
            $details = [
                'brand' => $brand,
                'model' => $model !== '' ? $model : null,
                'imei' => $imei !== '' ? $imei : null,
                ...$details,
            ];
        }

        return $details;
    }

    public static function handle(array $attributes): Product
    {
        return Product::create($attributes);
    }
}
