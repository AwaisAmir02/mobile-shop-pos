<?php

namespace App\Actions;

use App\Models\MainCategory;
use App\Models\Product;
use Illuminate\Validation\Rule;

class CreateProduct
{
    public static function rules(int $shopId, string $type): array
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
            $rules['brand'] = ['required', 'string', 'max:255'];
            $rules['model'] = ['required', 'string', 'max:255'];
            $rules['imei'] = ['nullable', 'string', 'max:50'];
        }

        return $rules;
    }

    public static function detailsForType(string $type, string $category, string $brand = '', string $model = '', string $imei = ''): array
    {
        $details = ['category' => $category !== '' ? $category : null];

        if ($type === 'mobile') {
            $details = [
                'brand' => $brand,
                'model' => $model,
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
