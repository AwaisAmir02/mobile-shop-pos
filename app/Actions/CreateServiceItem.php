<?php

namespace App\Actions;

use App\Models\ServiceItem;
use Illuminate\Validation\Rule;

class CreateServiceItem
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'serviceCategoryId' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('shop_id', $shopId)],
        ];
    }

    public static function handle(string $name, int $serviceCategoryId): ServiceItem
    {
        return ServiceItem::create([
            'name' => $name,
            'service_category_id' => $serviceCategoryId,
        ]);
    }
}
